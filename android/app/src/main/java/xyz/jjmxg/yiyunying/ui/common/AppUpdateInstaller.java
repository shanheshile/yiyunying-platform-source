package xyz.jjmxg.yiyunying.ui.common;

import android.Manifest;
import android.app.Activity;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.content.Intent;
import android.content.pm.PackageInfo;
import android.content.pm.PackageManager;
import android.net.Uri;
import android.os.Build;
import android.provider.Settings;
import android.view.Gravity;
import android.view.ViewGroup;
import android.widget.LinearLayout;
import android.widget.ProgressBar;
import android.widget.TextView;

import androidx.appcompat.app.AlertDialog;
import androidx.core.app.NotificationCompat;
import androidx.core.app.NotificationManagerCompat;
import androidx.core.content.ContextCompat;
import androidx.core.content.FileProvider;

import com.google.gson.JsonObject;

import java.io.File;
import java.io.FileInputStream;
import java.io.FileOutputStream;
import java.io.IOException;
import java.io.InputStream;
import java.security.MessageDigest;
import java.security.NoSuchAlgorithmException;
import java.util.Locale;
import java.util.concurrent.TimeUnit;

import okhttp3.Call;
import okhttp3.Callback;
import okhttp3.OkHttpClient;
import okhttp3.Request;
import okhttp3.Response;
import okhttp3.ResponseBody;
import xyz.jjmxg.yiyunying.core.CrashReporter;
import xyz.jjmxg.yiyunying.core.NotificationIconResolver;
import xyz.jjmxg.yiyunying.data.api.ApiClient;
import xyz.jjmxg.yiyunying.data.api.Jsons;

public final class AppUpdateInstaller {
    private static final String UPDATE_CHANNEL = "software_updates";
    private static final int UPDATE_NOTIFICATION_ID = 27150;

    private AppUpdateInstaller() { }

    public static void install(Activity activity, JsonObject update, boolean forced, Runnable onContinue) {
        if (!usable(activity)) return;
        JsonObject snapshot = update == null ? new JsonObject() : update.deepCopy();
        File directory = new File(activity.getCacheDir(), "updates");
        if (!directory.exists() && !directory.mkdirs()) {
            showFailure(activity, snapshot, forced, onContinue, "无法创建更新缓存目录。");
            return;
        }
        File temporary = new File(directory, "update.part.apk");
        File apk = new File(directory, "update.apk");
        String expectedHash = Jsons.string(snapshot, "sha256").trim().toLowerCase(Locale.ROOT);
        String expectedPackage = Jsons.string(snapshot, "package_name").trim();
        if (expectedPackage.isEmpty()) expectedPackage = activity.getPackageName();
        long expectedVersionCode = Jsons.longValue(snapshot, "version_code");
        long expectedSize = Jsons.longValue(snapshot, "size_bytes");

        if (apk.isFile()) {
            String requiredPackage = expectedPackage;
            Thread verifier = new Thread(() -> {
                boolean reusable = cachedPackageMatches(activity, apk, requiredPackage, expectedVersionCode,
                    expectedSize, expectedHash);
                activity.runOnUiThread(() -> {
                    if (!usable(activity)) return;
                    if (reusable) {
                        notifyReady(activity, apk);
                        requestInstall(activity, apk, forced, onContinue);
                        return;
                    }
                    deleteQuietly(apk);
                    deleteQuietly(temporary);
                    downloadAndInstall(activity, snapshot, forced, onContinue);
                });
            }, "update-cache-verifier");
            verifier.start();
            return;
        }
        deleteQuietly(temporary);
        downloadAndInstall(activity, snapshot, forced, onContinue);
    }

    private static void downloadAndInstall(
        Activity activity,
        JsonObject update,
        boolean forced,
        Runnable onContinue
    ) {
        if (!usable(activity)) return;
        JsonObject snapshot = update == null ? new JsonObject() : update.deepCopy();
        String rawUrl = Jsons.string(snapshot, "download_url");
        String url = ImageLoader.get().absoluteUrl(activity, rawUrl);
        if (url.isEmpty() && (rawUrl.startsWith("http://") || rawUrl.startsWith("https://"))) url = rawUrl;
        if (url.isEmpty()) {
            showFailure(activity, snapshot, forced, onContinue, "后台没有配置有效的 APK 下载地址。");
            return;
        }

        LinearLayout progressContent = new LinearLayout(activity);
        progressContent.setOrientation(LinearLayout.VERTICAL);
        int horizontalPadding = Math.round(24f * activity.getResources().getDisplayMetrics().density);
        progressContent.setPadding(horizontalPadding, 0, horizontalPadding, horizontalPadding / 2);
        TextView progressText = new TextView(activity);
        progressText.setText("正在连接下载服务器…");
        progressText.setGravity(Gravity.CENTER_HORIZONTAL);
        ProgressBar progressBar = new ProgressBar(activity, null, android.R.attr.progressBarStyleHorizontal);
        progressBar.setIndeterminate(true);
        progressBar.setMax(100);
        progressContent.addView(progressText, new LinearLayout.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));
        LinearLayout.LayoutParams progressParams = new LinearLayout.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT);
        progressParams.topMargin = horizontalPadding / 2;
        progressContent.addView(progressBar, progressParams);
        AlertDialog progress = new YiyunyingDialogBuilder(activity)
            .setTitle("正在下载更新")
            .setView(progressContent)
            .setCancelable(false)
            .create();
        progress.setCanceledOnTouchOutside(false);
        ensureNotificationChannel(activity);
        notifyProgress(activity, 0L, -1L);
        try {
            progress.show();
        } catch (RuntimeException | LinkageError exception) {
            CrashReporter.record("显示更新下载进度", exception);
            notifyFailure(activity, "无法显示下载进度，请稍后重试");
            if (!forced) runContinue(onContinue);
            return;
        }

        File directory = new File(activity.getCacheDir(), "updates");
        if (!directory.exists() && !directory.mkdirs()) {
            safeDismiss(progress);
            showFailure(activity, snapshot, forced, onContinue, "无法创建更新缓存目录。");
            return;
        }
        File temporary = new File(directory, "update.part.apk");
        File apk = new File(directory, "update.apk");
        String expectedHash = Jsons.string(snapshot, "sha256").trim().toLowerCase(Locale.ROOT);
        String expectedPackage = Jsons.string(snapshot, "package_name").trim();
        long expectedVersionCode = Jsons.longValue(snapshot, "version_code");
        long expectedSize = Jsons.longValue(snapshot, "size_bytes");

        OkHttpClient client = ApiClient.defaultHttpClient(activity).newBuilder()
            .followRedirects(true)
            .followSslRedirects(true)
            .readTimeout(5, TimeUnit.MINUTES)
            .callTimeout(8, TimeUnit.MINUTES)
            .build();
        Request request = new Request.Builder().url(url)
            .header("Accept", "application/vnd.android.package-archive,*/*").build();
        client.newCall(request).enqueue(new Callback() {
            @Override public void onFailure(Call call, IOException exception) {
                notifyFailure(activity, "下载更新失败，请检查网络后重试");
                activity.runOnUiThread(() -> {
                    if (usable(activity)) {
                        safeDismiss(progress);
                        showFailure(activity, snapshot, forced, onContinue, "下载更新失败，请检查网络后重试。");
                    }
                });
            }

            @Override public void onResponse(Call call, Response response) {
                String failure = null;
                try (Response safeResponse = response) {
                    if (!safeResponse.isSuccessful()) {
                        failure = "下载服务器返回 HTTP " + safeResponse.code() + "。";
                    } else {
                        ResponseBody body = safeResponse.body();
                        if (body == null) failure = "下载服务器没有返回安装包内容。";
                        else failure = saveAndVerify(activity, body, temporary, apk, expectedSize, expectedHash,
                            expectedPackage, expectedVersionCode, (downloaded, totalBytes) -> {
                                notifyProgress(activity, downloaded, totalBytes);
                                activity.runOnUiThread(() -> {
                                    if (activity.isFinishing() || activity.isDestroyed()) return;
                                    if (totalBytes <= 0L) {
                                        progressBar.setIndeterminate(true);
                                        progressText.setText("已下载 " + readableBytes(downloaded));
                                    } else {
                                        int percent = (int) Math.min(100L, downloaded * 100L / totalBytes);
                                        progressBar.setIndeterminate(false);
                                        progressBar.setProgress(percent);
                                        progressText.setText("已下载 " + percent + "%  ·  "
                                            + readableBytes(downloaded) + " / " + readableBytes(totalBytes));
                                    }
                                });
                            });
                    }
                } catch (IOException exception) {
                    failure = "保存安装包失败，请检查设备存储空间。";
                }
                String result = failure;
                if (result == null) notifyReady(activity, apk);
                else notifyFailure(activity, result);
                activity.runOnUiThread(() -> {
                    if (!usable(activity)) return;
                    safeDismiss(progress);
                    if (result != null) showFailure(activity, snapshot, forced, onContinue, result);
                    else requestInstall(activity, apk, forced, onContinue);
                });
            }
        });
    }

    private static String saveAndVerify(
        Activity activity,
        ResponseBody body,
        File temporary,
        File apk,
        long expectedSize,
        String expectedHash,
        String expectedPackage,
        long expectedVersionCode,
        ProgressCallback progress
    ) throws IOException {
        MessageDigest digest;
        try {
            digest = MessageDigest.getInstance("SHA-256");
        } catch (NoSuchAlgorithmException exception) {
            return "当前系统不支持 SHA-256 校验。";
        }
        long total = 0L;
        long contentLength = body.contentLength();
        long lastProgressAt = 0L;
        byte[] buffer = new byte[64 * 1024];
        try (InputStream input = body.byteStream(); FileOutputStream output = new FileOutputStream(temporary, false)) {
            int read;
            while ((read = input.read(buffer)) != -1) {
                output.write(buffer, 0, read);
                digest.update(buffer, 0, read);
                total += read;
                long now = android.os.SystemClock.elapsedRealtime();
                if (now - lastProgressAt >= 180L) {
                    progress.onProgress(total, contentLength);
                    lastProgressAt = now;
                }
            }
            output.getFD().sync();
        }
        progress.onProgress(total, contentLength);
        if (expectedSize > 0L && total != expectedSize) {
            temporary.delete();
            return "安装包大小校验失败，已阻止安装。";
        }
        String actualHash = hex(digest.digest());
        if (!expectedHash.isEmpty() && !expectedHash.equals(actualHash)) {
            temporary.delete();
            return "安装包完整性校验失败，已阻止安装。";
        }
        PackageInfo archiveInfo = archiveInfo(activity, temporary);
        String archivePackage = archiveInfo == null || archiveInfo.packageName == null
            ? "" : archiveInfo.packageName;
        if (archivePackage.isEmpty()) {
            temporary.delete();
            return "下载内容不是有效的 Android 安装包。";
        }
        String requiredPackage = expectedPackage.isEmpty() ? activity.getPackageName() : expectedPackage;
        if (!requiredPackage.equals(archivePackage)) {
            temporary.delete();
            return "安装包应用标识不匹配，已阻止安装。";
        }
        if (expectedVersionCode > 0L && archiveVersionCode(archiveInfo) != expectedVersionCode) {
            temporary.delete();
            return "安装包版本与更新信息不匹配，已阻止安装。";
        }
        if (apk.exists() && !apk.delete()) return "无法替换旧的更新缓存。";
        if (!temporary.renameTo(apk)) return "无法提交已校验的安装包。";
        return null;
    }

    private static PackageInfo archiveInfo(Activity activity, File apk) {
        PackageManager manager = activity.getPackageManager();
        if (Build.VERSION.SDK_INT >= 33) {
            return manager.getPackageArchiveInfo(apk.getAbsolutePath(), PackageManager.PackageInfoFlags.of(0));
        } else {
            //noinspection deprecation
            return manager.getPackageArchiveInfo(apk.getAbsolutePath(), 0);
        }
    }

    private static long archiveVersionCode(PackageInfo info) {
        if (info == null) return 0L;
        if (Build.VERSION.SDK_INT >= 28) return info.getLongVersionCode();
        //noinspection deprecation
        return info.versionCode;
    }

    private static boolean cachedPackageMatches(
        Activity activity,
        File apk,
        String expectedPackage,
        long expectedVersionCode,
        long expectedSize,
        String expectedHash
    ) {
        if (activity == null || apk == null || !apk.isFile()) return false;
        try {
            PackageInfo info = archiveInfo(activity, apk);
            String actualPackage = info == null ? "" : info.packageName;
            return UpdatePackagePolicy.matches(
                expectedPackage,
                expectedVersionCode,
                expectedSize,
                expectedHash,
                actualPackage,
                archiveVersionCode(info),
                apk.length(),
                sha256(apk)
            );
        } catch (IOException | RuntimeException exception) {
            CrashReporter.record("校验已下载更新", exception);
            return false;
        }
    }

    private static String sha256(File file) throws IOException {
        MessageDigest digest;
        try {
            digest = MessageDigest.getInstance("SHA-256");
        } catch (NoSuchAlgorithmException exception) {
            throw new IOException("SHA-256 unavailable", exception);
        }
        byte[] buffer = new byte[64 * 1024];
        try (InputStream input = new FileInputStream(file)) {
            int read;
            while ((read = input.read(buffer)) != -1) digest.update(buffer, 0, read);
        }
        return hex(digest.digest());
    }

    private static void deleteQuietly(File file) {
        if (file != null && file.exists() && !file.delete()) {
            CrashReporter.record("清理失效更新缓存", new IOException(file.getAbsolutePath()));
        }
    }

    private static void requestInstall(Activity activity, File apk, boolean forced, Runnable onContinue) {
        if (!usable(activity)) return;
        if (Build.VERSION.SDK_INT >= 26 && !activity.getPackageManager().canRequestPackageInstalls()) {
            try {
                new YiyunyingDialogBuilder(activity)
                    .setTitle("允许安装更新")
                    .setMessage("请在系统设置中允许易运盈安装更新，返回后再次点击立即更新。")
                    .setCancelable(!forced)
                    .setPositiveButton("打开设置", (dialog, which) -> {
                        if (!usable(activity)) return;
                        Intent intent = new Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES,
                            Uri.parse("package:" + activity.getPackageName()));
                        activity.startActivity(intent);
                    })
                    .setNegativeButton(forced ? "退出" : "稍后", (dialog, which) -> {
                        if (!usable(activity)) return;
                        if (forced) activity.finishAffinity(); else runContinue(onContinue);
                    })
                    .show();
            } catch (RuntimeException | LinkageError exception) {
                CrashReporter.record("显示更新安装权限提示", exception);
                if (!forced) runContinue(onContinue);
            }
            return;
        }
        Uri content = FileProvider.getUriForFile(activity, activity.getPackageName() + ".capture-files", apk);
        Intent install = installIntent(content);
        try {
            activity.startActivity(install);
            if (!forced) runContinue(onContinue);
        } catch (RuntimeException exception) {
            showFailure(activity, new JsonObject(), forced, onContinue, "系统没有可用的安装器。");
        }
    }

    private static Intent installIntent(Uri content) {
        return new Intent(Intent.ACTION_VIEW)
            .setDataAndType(content, "application/vnd.android.package-archive")
            .addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION | Intent.FLAG_ACTIVITY_NEW_TASK);
    }

    private static void showFailure(Activity activity, JsonObject update, boolean forced, Runnable onContinue,
                                    String message) {
        notifyFailure(activity, message);
        if (!usable(activity)) return;
        JsonObject snapshot = update == null ? new JsonObject() : update.deepCopy();
        com.google.android.material.dialog.MaterialAlertDialogBuilder builder = new YiyunyingDialogBuilder(activity)
            .setTitle("更新未完成")
            .setMessage(message == null || message.trim().isEmpty() ? "更新暂时无法完成，请稍后重试。" : message)
            .setCancelable(!forced);
        if (snapshot.has("download_url")) {
            builder.setPositiveButton("重试", (dialog, which) -> install(activity, snapshot, forced, onContinue));
        }
        builder.setNegativeButton(forced ? "退出" : "稍后", (dialog, which) -> {
            if (!usable(activity)) return;
            if (forced) activity.finishAffinity(); else runContinue(onContinue);
        });
        try {
            builder.show();
        } catch (RuntimeException | LinkageError exception) {
            CrashReporter.record("显示更新失败提示", exception);
            if (!forced) runContinue(onContinue);
        }
    }

    private static void ensureNotificationChannel(Activity activity) {
        if (Build.VERSION.SDK_INT < 26) return;
        NotificationManager manager = activity.getSystemService(NotificationManager.class);
        if (manager == null) return;
        NotificationChannel channel = new NotificationChannel(
            UPDATE_CHANNEL, "软件更新", NotificationManager.IMPORTANCE_LOW);
        channel.setDescription("显示软件更新下载进度和安装状态");
        channel.setSound(null, null);
        manager.createNotificationChannel(channel);
    }

    private static void notifyProgress(Activity activity, long downloaded, long total) {
        int percent = total > 0L ? (int) Math.min(100L, downloaded * 100L / total) : 0;
        String detail = total > 0L
            ? percent + "% · " + readableBytes(downloaded) + " / " + readableBytes(total)
            : (downloaded > 0L ? "已下载 " + readableBytes(downloaded) : "正在连接下载服务器…");
        NotificationCompat.Builder builder = notificationBuilder(activity)
            .setContentTitle("正在下载软件更新")
            .setContentText(detail)
            .setOngoing(true)
            .setOnlyAlertOnce(true)
            .setProgress(100, percent, total <= 0L);
        notifySafely(activity, builder);
    }

    private static void notifyReady(Activity activity, File apk) {
        NotificationCompat.Builder builder = notificationBuilder(activity)
            .setContentTitle("更新已下载")
            .setContentText("安装包校验通过，点击继续安装")
            .setOngoing(false)
            .setAutoCancel(true)
            .setProgress(0, 0, false);
        try {
            Uri content = FileProvider.getUriForFile(activity,
                activity.getPackageName() + ".capture-files", apk);
            PendingIntent install = PendingIntent.getActivity(activity, UPDATE_NOTIFICATION_ID,
                installIntent(content), PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);
            builder.setContentIntent(install);
        } catch (RuntimeException exception) {
            CrashReporter.record("创建更新安装通知", exception);
        }
        notifySafely(activity, builder);
    }

    private static void notifyFailure(Activity activity, String message) {
        if (activity == null) return;
        ensureNotificationChannel(activity);
        NotificationCompat.Builder builder = notificationBuilder(activity)
            .setContentTitle("更新未完成")
            .setContentText(message == null || message.trim().isEmpty() ? "请稍后重试" : message)
            .setOngoing(false)
            .setAutoCancel(true)
            .setProgress(0, 0, false);
        notifySafely(activity, builder);
    }

    private static NotificationCompat.Builder notificationBuilder(Activity activity) {
        return new NotificationCompat.Builder(activity, UPDATE_CHANNEL)
            .setSmallIcon(NotificationIconResolver.smallIcon(activity))
            .setPriority(NotificationCompat.PRIORITY_LOW)
            .setCategory(NotificationCompat.CATEGORY_PROGRESS)
            .setVisibility(NotificationCompat.VISIBILITY_PUBLIC);
    }

    private static void notifySafely(Activity activity, NotificationCompat.Builder builder) {
        if (activity == null || builder == null || !notificationsAllowed(activity)) return;
        try {
            NotificationManagerCompat.from(activity).notify(UPDATE_NOTIFICATION_ID, builder.build());
        } catch (SecurityException exception) {
            CrashReporter.record("更新通知权限不可用", exception);
        } catch (RuntimeException exception) {
            CrashReporter.record("显示更新通知", exception);
        }
    }

    private static boolean notificationsAllowed(Activity activity) {
        return Build.VERSION.SDK_INT < Build.VERSION_CODES.TIRAMISU
            || ContextCompat.checkSelfPermission(activity, Manifest.permission.POST_NOTIFICATIONS)
                == PackageManager.PERMISSION_GRANTED;
    }

    private static boolean usable(Activity activity) {
        return activity != null && !activity.isFinishing() && !activity.isDestroyed();
    }

    private static void safeDismiss(AlertDialog dialog) {
        if (dialog == null || !dialog.isShowing()) return;
        try {
            dialog.dismiss();
        } catch (RuntimeException ignored) {
            // Window may already be detached while the download callback is returning.
        }
    }

    private static void runContinue(Runnable onContinue) {
        if (onContinue == null) return;
        try {
            onContinue.run();
        } catch (RuntimeException | LinkageError exception) {
            CrashReporter.record("继续进入应用", exception);
        }
    }

    private static String hex(byte[] value) {
        StringBuilder result = new StringBuilder(value.length * 2);
        for (byte item : value) result.append(String.format(Locale.ROOT, "%02x", item & 0xff));
        return result.toString();
    }

    private static String readableBytes(long bytes) {
        if (bytes < 1024L) return bytes + " B";
        if (bytes < 1024L * 1024L) return String.format(Locale.ROOT, "%.1f KB", bytes / 1024d);
        return String.format(Locale.ROOT, "%.1f MB", bytes / 1024d / 1024d);
    }

    private interface ProgressCallback {
        void onProgress(long downloaded, long total);
    }
}
