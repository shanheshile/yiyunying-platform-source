package xyz.jjmxg.yiyunying.ui.upload;

import android.content.Context;
import android.net.Uri;

import java.io.InputStream;
import java.nio.charset.StandardCharsets;
import java.util.Locale;

public final class MediaKindDetector {
    private static final int XMP_SCAN_BYTES = 384 * 1024;

    private MediaKindDetector() { }

    public static boolean isGif(String mime, String name) {
        String lower = name == null ? "" : name.toLowerCase(Locale.ROOT);
        return "image/gif".equalsIgnoreCase(mime) || lower.endsWith(".gif");
    }

    public static boolean isMotionPhotoNameHint(String mime, String name) {
        if (isGif(mime, name)) return false;
        String lowerName = name == null ? "" : name.toLowerCase(Locale.ROOT);
        return lowerName.startsWith("mvimg_") || lowerName.contains("motion_photo")
            || lowerName.contains("motionphoto") || lowerName.contains("livephoto");
    }

    public static boolean isMotionPhoto(Context context, Uri uri, String mime, String name) {
        if (context == null || uri == null || isGif(mime, name)) return false;
        if (isMotionPhotoNameHint(mime, name)) return true;
        String lowerMime = mime == null ? "" : mime.toLowerCase(Locale.ROOT);
        if (!lowerMime.isEmpty() && !lowerMime.contains("jpeg") && !lowerMime.contains("heic")
            && !lowerMime.contains("heif")) return false;
        byte[] buffer = new byte[XMP_SCAN_BYTES];
        int total = 0;
        try (InputStream input = context.getContentResolver().openInputStream(uri)) {
            if (input == null) return false;
            while (total < buffer.length) {
                int read = input.read(buffer, total, buffer.length - total);
                if (read < 0) break;
                total += read;
            }
        } catch (Exception ignored) {
            return false;
        }
        if (total <= 0) return false;
        String header = new String(buffer, 0, total, StandardCharsets.ISO_8859_1);
        return header.contains("GCamera:MotionPhoto=\"1\"")
            || header.contains("Camera:MotionPhoto=\"1\"")
            || header.contains("GCamera:MicroVideo=\"1\"")
            || header.contains("Camera:MicroVideo=\"1\"")
            || header.contains("MotionPhoto_Data")
            || header.contains("MotionPhotoVersion");
    }
}
