package xyz.jjmxg.yiyunying.ui.upload;

import android.content.Context;
import android.graphics.Bitmap;
import android.graphics.BitmapFactory;
import android.graphics.Matrix;
import android.media.MediaMetadataRetriever;
import android.net.Uri;
import android.os.Handler;
import android.os.Looper;

import androidx.annotation.NonNull;
import androidx.exifinterface.media.ExifInterface;
import androidx.media3.common.MediaItem;
import androidx.media3.common.MimeTypes;
import androidx.media3.common.util.UnstableApi;
import androidx.media3.effect.Presentation;
import androidx.media3.transformer.Composition;
import androidx.media3.transformer.EditedMediaItem;
import androidx.media3.transformer.Effects;
import androidx.media3.transformer.ExportException;
import androidx.media3.transformer.ExportResult;
import androidx.media3.transformer.Transformer;

import java.io.File;
import java.io.FileOutputStream;
import java.io.IOException;
import java.io.InputStream;
import java.util.Collections;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

@UnstableApi
public final class LocalMediaOptimizer {
    public interface Callback {
        void onFinished(Result result);
    }

    public static final class Result {
        public final Uri uri;
        public final long originalBytes;
        public final long outputBytes;
        public final boolean optimized;
        public final String message;

        private Result(Uri uri, long originalBytes, long outputBytes, boolean optimized, String message) {
            this.uri = uri;
            this.originalBytes = Math.max(0L, originalBytes);
            this.outputBytes = Math.max(0L, outputBytes);
            this.optimized = optimized;
            this.message = message == null ? "" : message;
        }
    }

    private static final ExecutorService IMAGE_EXECUTOR = Executors.newSingleThreadExecutor();
    private static final Handler MAIN = new Handler(Looper.getMainLooper());

    private LocalMediaOptimizer() { }

    public static void optimize(Context context, Uri source, boolean video, Callback callback) {
        if (source == null) {
            callback.onFinished(new Result(Uri.EMPTY, 0, 0, false, "没有可处理的拍摄文件"));
            return;
        }
        if (video) optimizeVideo(context.getApplicationContext(), source, callback);
        else optimizeImage(context.getApplicationContext(), source, callback);
    }

    private static void optimizeImage(Context context, Uri source, Callback callback) {
        IMAGE_EXECUTOR.execute(() -> {
            long sourceSize = size(context, source);
            File output = outputFile(context, "photo", ".jpg");
            try {
                BitmapFactory.Options bounds = new BitmapFactory.Options();
                bounds.inJustDecodeBounds = true;
                try (InputStream input = context.getContentResolver().openInputStream(source)) {
                    BitmapFactory.decodeStream(input, null, bounds);
                }
                int maxSide = Math.max(bounds.outWidth, bounds.outHeight);
                int sample = 1;
                while (maxSide / sample > 4096) sample *= 2;
                BitmapFactory.Options decode = new BitmapFactory.Options();
                decode.inSampleSize = Math.max(1, sample);
                Bitmap bitmap;
                try (InputStream input = context.getContentResolver().openInputStream(source)) {
                    bitmap = BitmapFactory.decodeStream(input, null, decode);
                }
                if (bitmap == null) throw new IOException("无法解码照片");
                Bitmap oriented = orient(context, source, bitmap);
                try (FileOutputStream stream = new FileOutputStream(output)) {
                    if (!oriented.compress(Bitmap.CompressFormat.JPEG, 86, stream)) {
                        throw new IOException("照片压缩失败");
                    }
                }
                if (oriented != bitmap) bitmap.recycle();
                oriented.recycle();
                long outputSize = output.length();
                if (outputSize <= 0 || (sourceSize > 0 && outputSize >= sourceSize)) {
                    delete(output);
                    finish(callback, new Result(source, sourceSize, sourceSize, false, "原照片已经足够精简，继续使用原文件"));
                    return;
                }
                finish(callback, new Result(Uri.fromFile(output), sourceSize, outputSize, true, "照片已在本地优化"));
            } catch (IOException | RuntimeException error) {
                delete(output);
                finish(callback, new Result(source, sourceSize, sourceSize, false,
                    "照片本地优化未完成，已保留原文件：" + safeMessage(error)));
            }
        });
    }

    private static Bitmap orient(Context context, Uri source, Bitmap bitmap) {
        int orientation = ExifInterface.ORIENTATION_NORMAL;
        try (InputStream input = context.getContentResolver().openInputStream(source)) {
            if (input != null) orientation = new ExifInterface(input).getAttributeInt(
                ExifInterface.TAG_ORIENTATION, ExifInterface.ORIENTATION_NORMAL);
        } catch (IOException | RuntimeException ignored) { }
        Matrix matrix = new Matrix();
        if (orientation == ExifInterface.ORIENTATION_ROTATE_90) matrix.postRotate(90f);
        else if (orientation == ExifInterface.ORIENTATION_ROTATE_180) matrix.postRotate(180f);
        else if (orientation == ExifInterface.ORIENTATION_ROTATE_270) matrix.postRotate(270f);
        else if (orientation == ExifInterface.ORIENTATION_FLIP_HORIZONTAL) matrix.postScale(-1f, 1f);
        else if (orientation == ExifInterface.ORIENTATION_FLIP_VERTICAL) matrix.postScale(1f, -1f);
        if (matrix.isIdentity()) return bitmap;
        return Bitmap.createBitmap(bitmap, 0, 0, bitmap.getWidth(), bitmap.getHeight(), matrix, true);
    }

    private static void optimizeVideo(Context context, Uri source, Callback callback) {
        long sourceSize = size(context, source);
        int shortSide = videoShortSide(context, source);
        int outputShortSide = shortSide > 0 ? Math.min(shortSide, 1080) : 720;
        File output = outputFile(context, "video", ".mp4");
        Transformer transformer = new Transformer.Builder(context)
            .setVideoMimeType(MimeTypes.VIDEO_H264)
            .setAudioMimeType(MimeTypes.AUDIO_AAC)
            .addListener(new Transformer.Listener() {
                @Override public void onCompleted(@NonNull Composition composition, @NonNull ExportResult exportResult) {
                    long outputSize = output.length();
                    if (outputSize <= 0 || (sourceSize > 0 && outputSize >= sourceSize)) {
                        delete(output);
                        callback.onFinished(new Result(source, sourceSize, sourceSize, false,
                            "原录像体积更合适，继续使用原文件"));
                        return;
                    }
                    callback.onFinished(new Result(Uri.fromFile(output), sourceSize, outputSize, true,
                        "录像已在本地转为通用 MP4"));
                }

                @Override public void onError(
                    @NonNull Composition composition,
                    @NonNull ExportResult exportResult,
                    @NonNull ExportException exportException
                ) {
                    delete(output);
                    callback.onFinished(new Result(source, sourceSize, sourceSize, false,
                        "录像本地转换未完成，已保留原文件：" + safeMessage(exportException)));
                }
            })
            .build();
        EditedMediaItem edited = new EditedMediaItem.Builder(MediaItem.fromUri(source))
            .setEffects(new Effects(
                Collections.emptyList(),
                Collections.singletonList(Presentation.createForShortSide(Math.max(360, outputShortSide)))))
            .build();
        try {
            transformer.start(edited, output.getAbsolutePath());
        } catch (RuntimeException error) {
            delete(output);
            callback.onFinished(new Result(source, sourceSize, sourceSize, false,
                "录像本地转换无法启动，已保留原文件：" + safeMessage(error)));
        }
    }

    private static int videoShortSide(Context context, Uri source) {
        MediaMetadataRetriever retriever = new MediaMetadataRetriever();
        try {
            retriever.setDataSource(context, source);
            int width = integer(retriever.extractMetadata(MediaMetadataRetriever.METADATA_KEY_VIDEO_WIDTH));
            int height = integer(retriever.extractMetadata(MediaMetadataRetriever.METADATA_KEY_VIDEO_HEIGHT));
            return width > 0 && height > 0 ? Math.min(width, height) : 0;
        } catch (RuntimeException ignored) {
            return 0;
        } finally {
            try { retriever.release(); } catch (IOException ignored) { }
        }
    }

    private static long size(Context context, Uri source) {
        try (android.os.ParcelFileDescriptor descriptor =
                 context.getContentResolver().openFileDescriptor(source, "r")) {
            return descriptor == null ? 0L : Math.max(0L, descriptor.getStatSize());
        } catch (IOException | RuntimeException ignored) {
            return 0L;
        }
    }

    private static File outputFile(Context context, String prefix, String suffix) {
        File directory = new File(context.getCacheDir(), "media_optimized");
        if (!directory.exists()) directory.mkdirs();
        return new File(directory, prefix + "_" + System.currentTimeMillis() + suffix);
    }

    private static int integer(String value) {
        try { return value == null ? 0 : Integer.parseInt(value); }
        catch (NumberFormatException ignored) { return 0; }
    }

    private static void finish(Callback callback, Result result) {
        MAIN.post(() -> callback.onFinished(result));
    }

    private static void delete(File file) {
        if (file != null && file.exists()) file.delete();
    }

    private static String safeMessage(Throwable error) {
        String message = error == null ? "设备暂不支持此格式" : error.getMessage();
        if (message == null || message.trim().isEmpty()) return "设备暂不支持此格式";
        message = message.trim();
        return message.length() > 60 ? message.substring(0, 60) : message;
    }
}
