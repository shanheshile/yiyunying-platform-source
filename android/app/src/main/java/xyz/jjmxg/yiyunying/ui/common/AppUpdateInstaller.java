package xyz.jjmxg.yiyunying.ui.common;

import android.app.Activity;
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
import androidx.core.content.FileProvider;

import xyz.jjmxg.yiyunying.ui.common.YiyunyingDialogBuilder;
import com.google.gson.JsonObject;

import java.io.File;
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
import xyz.jjmxg.yiyunying.data.api.ApiClient;
import xyz.jjmxg.yiyunying.data.api.Jsons;

public final class AppUpdateInstaller {
    private AppUpdateInstaller() { }

    public static void install(Activity activity, JsonObject update, boolean forced, Runnable onContinue) {
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
            .setCancelable(!forced)
            .create();
        try {
            progress.show();
        } catch (RuntimeException | LinkageError exception) {
            CrashReporter.record("显示更新下载进度", exception);
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
        long expectedSize = Jsons.longValue(snapshot, "size_bytes");

        OkHttpClient client = ApiClient.defaultHttpClient(activity).newBuilder()
            .followRedirects(true)
            .followSslRedirects(true)
            .readTimeout(5, TimeUnit.MINUTES)
            .callTimeout(8, TimeUnit.MINUTES)
            .build();
        Request request = new Request.Builder().url(url).header("Accept", "application/vnd.android.package-archive,*/*").build();
        client.newCall(request).enqueue(new Callback() {
            @Override public void onFailure(Call call, IOException exception) {
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
                        else failure = saveAndVerify(activity, body, temporary, apk, expectedSize, expectedHash, expectedPackage,
                            (downloaded, totalBytes) -> activity.runOnUiThread(() -> {
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
                            }));
                    }
                } catch (IOException exception) {
                    failure = "保存安装包失败，请检查设备存储空间。";
                }
                String result = failure;
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
        String archivePackage = archivePackage(activity, temporary);
        if (archivePackage.isEmpty()) {
            temporary.delete();
            return "下载内容不是有效的 Android 安装包。";
        }
        String requiredPackage = expectedPackage.isEmpty() ? activity.getPackageName() : expectedPackage;
        if (!requiredPackage.equals(archivePackage)) {
            temporary.delete();
            return "安装包应用标识不匹配，已阻止安装。";
        }
        if (apk.exists() && !apk.delete()) return "无法替换旧的更新缓存。";
        if (!temporary.renameTo(apk)) return "无法提交已校验的安装包。";
        return null;
    }

    private static String archivePackage(Activity activity, File apk) {
        PackageManager manager = activity.getPackageManager();
        PackageInfo info;
        if (Build.VERSION.SDK_INT >= 33) {
            info = manager.getPackageArchiveInfo(apk.getAbsolutePath(), PackageManager.PackageInfoFlags.of(0));
        } else {
            //noinspection deprecation
            info = manager.getPackageArchiveInfo(apk.getAbsolutePath(), 0);
        }
        return info == null || info.packageName == null ? "" : info.packageName;
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
        Intent install = new Intent(Intent.ACTION_VIEW)
            .setDataAndType(content, "application/vnd.android.package-archive")
            .addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION | Intent.FLAG_ACTIVITY_NEW_TASK);
        try {
            activity.startActivity(install);
            if (!forced) runContinue(onContinue);
        } catch (RuntimeException exception) {
            showFailure(activity, new JsonObject(), forced, onContinue, "系统没有可用的安装器。");
        }
    }

    private static void showFailure(Activity activity, JsonObject update, boolean forced, Runnable onContinue, String message) {
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
