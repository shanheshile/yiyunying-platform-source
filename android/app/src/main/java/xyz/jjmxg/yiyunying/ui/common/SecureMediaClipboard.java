package xyz.jjmxg.yiyunying.ui.common;

import android.content.ClipData;
import android.content.ClipDescription;
import android.content.ClipboardManager;
import android.content.Context;
import android.net.Uri;
import android.os.Handler;
import android.os.Looper;
import android.webkit.MimeTypeMap;
import android.view.View;

import androidx.core.content.FileProvider;
import androidx.core.view.ContentInfoCompat;
import androidx.core.view.ViewCompat;

import com.google.gson.JsonElement;
import com.google.gson.JsonObject;

import java.io.File;
import java.io.FileOutputStream;
import java.io.IOException;
import java.io.InputStream;
import java.util.ArrayList;
import java.util.LinkedHashSet;
import java.util.List;
import java.util.Locale;
import java.util.Set;
import java.util.UUID;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.TimeUnit;

import okhttp3.OkHttpClient;
import okhttp3.Request;
import okhttp3.Response;
import okhttp3.ResponseBody;
import xyz.jjmxg.yiyunying.core.AppAccess;
import xyz.jjmxg.yiyunying.data.api.Jsons;

/** Copies remote message media as private content URIs instead of exposing server URLs. */
public final class SecureMediaClipboard {
    private static final long MAX_CACHE_AGE_MS = TimeUnit.HOURS.toMillis(24);
    private static final String[] ACCEPTED_MIME_TYPES = {
        "image/*", "video/*", "audio/*", "application/*"
    };
    private static final ExecutorService IO = Executors.newFixedThreadPool(2);
    private static final Handler MAIN = new Handler(Looper.getMainLooper());
    private static final OkHttpClient HTTP = new OkHttpClient.Builder()
        .connectTimeout(20, TimeUnit.SECONDS)
        .readTimeout(90, TimeUnit.SECONDS)
        .callTimeout(120, TimeUnit.SECONDS)
        .retryOnConnectionFailure(true)
        .followRedirects(true)
        .followSslRedirects(true)
        .build();

    private SecureMediaClipboard() { }

    public interface CopyCallback {
        void onComplete(boolean success, int count, String message);
    }

    public interface MediaPasteListener {
        void onMediaPaste(List<Uri> uris);
    }

    public static boolean hasCopyableMedia(JsonObject message) {
        if (message == null) return false;
        for (JsonElement element : Jsons.array(message, "attachments")) {
            if (element.isJsonObject() && isCopyableType(Jsons.string(element.getAsJsonObject(), "media_type"))) {
                String url = Jsons.string(element.getAsJsonObject(), "url");
                if (!url.trim().isEmpty()) return true;
            }
        }
        return false;
    }

    public static void copyMessageMedia(Context context, JsonObject message, CopyCallback callback) {
        Context appContext = context.getApplicationContext();
        List<JsonObject> attachments = copyableAttachments(message);
        if (attachments.isEmpty()) {
            complete(callback, false, 0, "这条消息没有可复制的媒体文件");
            return;
        }
        IO.execute(() -> {
            cleanupOldFiles(appContext);
            List<Uri> uris = new ArrayList<>();
            Set<String> mimeTypes = new LinkedHashSet<>();
            String failure = "";
            for (JsonObject attachment : attachments) {
                try {
                    DownloadedFile downloaded = download(appContext, attachment);
                    uris.add(downloaded.uri);
                    mimeTypes.add(downloaded.mimeType);
                } catch (IOException | RuntimeException exception) {
                    failure = friendlyFailure(exception);
                    break;
                }
            }
            if (uris.size() != attachments.size()) {
                complete(callback, false, uris.size(), failure.isEmpty() ? "媒体文件准备失败，请稍后重试" : failure);
                return;
            }
            try {
                ClipDescription description = new ClipDescription(
                    "易运盈媒体文件",
                    mimeTypes.isEmpty() ? new String[]{"application/octet-stream"} : mimeTypes.toArray(new String[0])
                );
                ClipData clip = new ClipData(description, new ClipData.Item(uris.get(0)));
                for (int index = 1; index < uris.size(); index++) clip.addItem(new ClipData.Item(uris.get(index)));
                MAIN.post(() -> {
                    ClipboardManager clipboard = (ClipboardManager) appContext.getSystemService(Context.CLIPBOARD_SERVICE);
                    if (clipboard == null) {
                        if (callback != null) callback.onComplete(false, 0, "系统剪贴板不可用");
                        return;
                    }
                    clipboard.setPrimaryClip(clip);
                    if (callback != null) callback.onComplete(true, uris.size(), "");
                });
            } catch (RuntimeException exception) {
                complete(callback, false, 0, "无法写入系统剪贴板");
            }
        });
    }

    public static void attachPaste(View target, MediaPasteListener listener) {
        if (target == null || listener == null) return;
        ViewCompat.setOnReceiveContentListener(target, ACCEPTED_MIME_TYPES, (view, payload) -> {
            ClipData clip = payload.getClip();
            List<Uri> uris = new ArrayList<>();
            for (int index = 0; index < clip.getItemCount(); index++) {
                Uri uri = clip.getItemAt(index).getUri();
                if (uri != null && "content".equalsIgnoreCase(uri.getScheme())) uris.add(uri);
            }
            if (uris.isEmpty()) return payload;
            listener.onMediaPaste(uris);
            return null;
        });
    }

    /** Returns only private content URIs from the current clipboard. Plain server URLs are ignored. */
    public static List<Uri> clipboardMediaUris(Context context) {
        List<Uri> result = new ArrayList<>();
        if (context == null) return result;
        ClipboardManager clipboard = (ClipboardManager) context.getSystemService(Context.CLIPBOARD_SERVICE);
        ClipData clip = clipboard == null ? null : clipboard.getPrimaryClip();
        if (clip == null) return result;
        Set<String> seen = new LinkedHashSet<>();
        for (int index = 0; index < clip.getItemCount(); index++) {
            Uri uri = clip.getItemAt(index).getUri();
            if (uri == null || !"content".equalsIgnoreCase(uri.getScheme())) continue;
            if (seen.add(uri.toString())) result.add(uri);
        }
        return result;
    }

    private static List<JsonObject> copyableAttachments(JsonObject message) {
        List<JsonObject> result = new ArrayList<>();
        if (message == null) return result;
        for (JsonElement element : Jsons.array(message, "attachments")) {
            if (!element.isJsonObject()) continue;
            JsonObject attachment = element.getAsJsonObject();
            if (isCopyableType(Jsons.string(attachment, "media_type"))
                && !Jsons.string(attachment, "url").trim().isEmpty()) {
                result.add(attachment.deepCopy());
            }
        }
        return result;
    }

    private static boolean isCopyableType(String type) {
        String normalized = type == null ? "" : type.trim().toLowerCase(Locale.ROOT);
        return "image".equals(normalized) || "video".equals(normalized)
            || "audio".equals(normalized) || "file".equals(normalized)
            || "document".equals(normalized) || "sticker".equals(normalized)
            || "gif".equals(normalized);
    }

    private static DownloadedFile download(Context context, JsonObject attachment) throws IOException {
        String source = ImageLoader.get().absoluteUrl(context, Jsons.string(attachment, "url"));
        if (source.isEmpty()) throw new IOException("媒体地址无效");
        Request.Builder builder = new Request.Builder().url(source).header("Accept", "*/*");
        String token = AppAccess.from(context).session().accessToken();
        String appKey = AppAccess.from(context).session().appKey();
        if (!token.isEmpty()) builder.header("Authorization", "Bearer " + token);
        if (!appKey.isEmpty()) builder.header("X-App-Key", appKey);
        try (Response response = HTTP.newCall(builder.get().build()).execute()) {
            if (!response.isSuccessful()) throw new IOException("服务器返回 HTTP " + response.code());
            ResponseBody body = response.body();
            if (body == null) throw new IOException("服务器没有返回文件内容");
            String mimeType = firstNonEmpty(
                Jsons.string(attachment, "mime_type"),
                body.contentType() == null ? "" : body.contentType().toString(),
                mimeForType(Jsons.string(attachment, "media_type"))
            );
            if (mimeType.contains(";")) mimeType = mimeType.substring(0, mimeType.indexOf(';')).trim();
            String name = firstNonEmpty(
                Jsons.string(attachment, "original_name"),
                Jsons.string(attachment, "file_name"),
                Uri.parse(source).getLastPathSegment(),
                "media"
            );
            name = safeFileName(name, mimeType);
            File directory = new File(context.getCacheDir(), "clipboard");
            if (!directory.exists() && !directory.mkdirs()) throw new IOException("无法创建媒体临时目录");
            File output = new File(directory, UUID.randomUUID().toString().substring(0, 8) + "_" + name);
            try (InputStream input = body.byteStream(); FileOutputStream stream = new FileOutputStream(output)) {
                byte[] buffer = new byte[32 * 1024];
                int read;
                while ((read = input.read(buffer)) != -1) stream.write(buffer, 0, read);
                stream.flush();
            }
            Uri uri = FileProvider.getUriForFile(context, context.getPackageName() + ".capture-files", output);
            return new DownloadedFile(uri, mimeType.isEmpty() ? "application/octet-stream" : mimeType);
        }
    }

    private static String safeFileName(String rawName, String mimeType) {
        String value = rawName == null ? "media" : rawName.trim();
        int query = value.indexOf('?');
        if (query >= 0) value = value.substring(0, query);
        value = value.replaceAll("[\\\\/:*?\"<>|\\r\\n]+", "_");
        if (value.isEmpty() || ".".equals(value) || "..".equals(value)) value = "media";
        if (value.length() > 96) value = value.substring(value.length() - 96);
        if (!value.contains(".")) {
            String extension = MimeTypeMap.getSingleton().getExtensionFromMimeType(mimeType);
            if (extension != null && !extension.isEmpty()) value += "." + extension;
        }
        return value;
    }

    private static String mimeForType(String type) {
        if ("image".equals(type) || "sticker".equals(type) || "gif".equals(type)) return "image/*";
        if ("video".equals(type)) return "video/*";
        if ("audio".equals(type)) return "audio/*";
        return "application/octet-stream";
    }

    private static String firstNonEmpty(String... values) {
        for (String value : values) if (value != null && !value.trim().isEmpty()) return value.trim();
        return "";
    }

    private static void cleanupOldFiles(Context context) {
        File directory = new File(context.getCacheDir(), "clipboard");
        File[] files = directory.listFiles();
        if (files == null) return;
        long cutoff = System.currentTimeMillis() - MAX_CACHE_AGE_MS;
        for (File file : files) if (file.isFile() && file.lastModified() < cutoff) file.delete();
    }

    private static void complete(CopyCallback callback, boolean success, int count, String message) {
        if (callback != null) MAIN.post(() -> callback.onComplete(success, count, message));
    }

    private static String friendlyFailure(Exception exception) {
        String message = exception.getMessage();
        if (message == null || message.trim().isEmpty()) return "媒体文件准备失败，请稍后重试";
        if (message.length() > 80) message = message.substring(0, 80);
        return "媒体文件准备失败：" + message;
    }

    private static final class DownloadedFile {
        final Uri uri;
        final String mimeType;

        DownloadedFile(Uri uri, String mimeType) {
            this.uri = uri;
            this.mimeType = mimeType;
        }
    }
}
