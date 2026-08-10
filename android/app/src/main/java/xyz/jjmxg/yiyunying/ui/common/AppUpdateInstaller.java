package xyz.jjmxg.yiyunying.ui.common;

import android.Manifest;
import android.app.Activity;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.content.Intent;
import android.content.pm.PackageInfo;
import android.content.pm.PackageManager;
import android.content.pm.Signature;
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
import androidx.lifecycle.Lifecycle;
import androidx.lifecycle.LifecycleOwner;

import com.google.gson.JsonObject;

import java.io.File;
import java.io.FileInputStream;
import java.io.IOException;
import java.io.InputStream;
import java.security.MessageDigest;
import java.security.NoSuchAlgorithmException;
import java.util.ArrayList;
import java.util.HashSet;
import java.util.List;
import java.util.Locale;
import java.util.Set;
import java.util.concurrent.ConcurrentHashMap;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.atomic.AtomicBoolean;

import okhttp3.Call;
import okhttp3.Callback;
import okhttp3.OkHttpClient;
import okhttp3.Response;
import okhttp3.ResponseBody;
import xyz.jjmxg.yiyunying.core.CrashReporter;
import xyz.jjmxg.yiyunying.core.NotificationIconResolver;
import xyz.jjmxg.yiyunying.BuildConfig;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.data.update.UpdatePackageStore;
import xyz.jjmxg.yiyunying.ui.upload.UpdatePackageHistoryActivity;

/** Downloads, verifies and installs the application update selected by the lifecycle service. */
public final class AppUpdateInstaller {
    private static final String UPDATE_CHANNEL = "software_updates";
    private static final int UPDATE_NOTIFICATION_ID = 27150;
    private static final ConcurrentHashMap<String, DownloadSession> ACTIVE_DOWNLOADS =
        new ConcurrentHashMap<>();
    private static final ConcurrentHashMap<String, VerificationSession> ACTIVE_VERIFICATIONS =
        new ConcurrentHashMap<>();
    private static final Object OPERATION_LOCK = new Object();
    private static final OkHttpClient UPDATE_HTTP_CLIENT = new OkHttpClient.Builder()
        .cache(null)
        .followRedirects(true)
        .followSslRedirects(true)
        .connectTimeout(15, TimeUnit.SECONDS)
        .readTimeout(2, TimeUnit.MINUTES)
        .writeTimeout(25, TimeUnit.SECONDS)
        .callTimeout(0, TimeUnit.MILLISECONDS)
        .retryOnConnectionFailure(true)
        .build();

    private AppUpdateInstaller() { }

    public static void install(Activity activity, JsonObject update, boolean forced, Runnable onContinue) {
        if (!usable(activity)) return;
        JsonObject snapshot = update == null ? new JsonObject() : update.deepCopy();
        String rawUrl = Jsons.string(snapshot, "download_url").trim();
        String url = ImageLoader.get().absoluteUrl(activity, rawUrl);
        if (url.isEmpty() && (rawUrl.startsWith("http://") || rawUrl.startsWith("https://"))) url = rawUrl;
        if (!UpdateTransportPolicy.allows(url, url, BuildConfig.ALLOW_HTTP_ENDPOINTS)) {
            showFailure(activity, snapshot, forced, onContinue, "后台没有配置有效的 APK 下载地址。");
            return;
        }

        UpdatePackageStore.Entry entry;
        try {
            entry = UpdatePackageStore.prepare(activity, snapshot, url);
        } catch (IllegalArgumentException | IllegalStateException exception) {
            showFailure(activity, snapshot, forced, onContinue,
                exception.getMessage() == null ? "更新信息不完整，已阻止下载。" : exception.getMessage());
            return;
        }
        DownloadSession activeDownload;
        VerificationSession activeVerification;
        synchronized (OPERATION_LOCK) {
            activeDownload = ACTIVE_DOWNLOADS.get(entry.id);
            activeVerification = ACTIVE_VERIFICATIONS.get(entry.id);
            if (activeDownload != null) activeDownload.attach(activity, forced, onContinue);
            else if (activeVerification != null) activeVerification.attach(activity, forced, onContinue);
        }
        if (activeDownload != null || activeVerification != null) {
            showAlreadyDownloading(activity, forced, onContinue);
            return;
        }
        File apk = UpdatePackageStore.apkFile(activity, entry.id);
        if (apk.isFile()) {
            verifyThenInstall(activity, entry, forced, onContinue, true);
            return;
        }
        File part = UpdatePackageStore.partFile(activity, entry.id);
        if (part.isFile() && part.length() == entry.expectedSize) {
            verifyCompletePartThenInstall(activity, entry, forced, onContinue);
            return;
        }
        if (part.isFile() && part.length() > entry.expectedSize) {
            deleteQuietly(part, "清理越界更新分片");
            persistProgress(activity, entry, "", "", 0L);
        }
        startDownload(activity, entry, forced, onContinue);
    }

    /** Resumes the exact update represented by a package-history row. */
    public static void resumeStoredDownload(Activity activity, String recordId) {
        UpdatePackageStore.Entry entry = UpdatePackageStore.find(activity, recordId);
        if (entry == null) {
            showFailure(activity, new JsonObject(), false, null, "找不到这条安装包记录。");
            return;
        }
        install(activity, entry.snapshot(), entry.forced, null);
    }

    /** Revalidates a persisted APK before opening Android's package installer. */
    public static void installStoredPackage(Activity activity, String recordId) {
        UpdatePackageStore.Entry entry = UpdatePackageStore.find(activity, recordId);
        if (entry == null || !entry.hasApk) {
            showFailure(activity, new JsonObject(), false, null, "安装包不存在或尚未下载完成。");
            return;
        }
        verifyThenInstall(activity, entry, entry.forced, null, false);
    }

    /** Called by the shared activity base after returning from the unknown-sources settings page. */
    public static void resumePendingInstall(Activity activity) {
        if (!usable(activity)) return;
        UpdatePackageStore.Entry entry = UpdatePackageStore.newestPermissionPending(activity);
        if (entry == null || !entry.hasApk) return;
        requestInstall(activity, entry, entry.forced, null);
    }

    /** Requests cancellation without racing deletion against the active response stream. */
    public static boolean cancelDownload(String recordId) {
        DownloadSession session = recordId == null ? null : ACTIVE_DOWNLOADS.get(recordId);
        if (session != null) {
            session.paused.set(true);
            Call call = session.call;
            if (call != null) call.cancel();
            return true;
        }
        VerificationSession verification = recordId == null
            ? null : ACTIVE_VERIFICATIONS.get(recordId);
        if (verification == null) return false;
        verification.paused.set(true);
        return true;
    }

    public static boolean isDownloadActive(String recordId) {
        return recordId != null && (ACTIVE_DOWNLOADS.containsKey(recordId)
            || ACTIVE_VERIFICATIONS.containsKey(recordId));
    }

    private static void verifyThenInstall(
        Activity activity,
        UpdatePackageStore.Entry entry,
        boolean forced,
        Runnable onContinue,
        boolean redownloadOnFailure
    ) {
        VerificationSession verification = new VerificationSession(
            activity, entry, forced, onContinue);
        DownloadSession activeDownload;
        VerificationSession activeVerification;
        synchronized (OPERATION_LOCK) {
            activeDownload = ACTIVE_DOWNLOADS.get(entry.id);
            activeVerification = ACTIVE_VERIFICATIONS.get(entry.id);
            if (activeDownload == null && activeVerification == null) {
                ACTIVE_VERIFICATIONS.put(entry.id, verification);
            } else if (activeDownload != null) {
                activeDownload.attach(activity, forced, onContinue);
            } else {
                activeVerification.attach(activity, forced, onContinue);
            }
        }
        if (activeDownload != null || activeVerification != null) {
            showAlreadyDownloading(activity, forced, onContinue);
            return;
        }
        Thread verifier = new Thread(() -> {
            boolean reusable = false;
            try {
                File apk = UpdatePackageStore.apkFile(activity, entry.id);
                reusable = verifiedPackageMatches(activity, apk, entry);
            } catch (RuntimeException exception) {
                CrashReporter.record("校验历史更新安装包", exception);
            }
            boolean verified = reusable;
            activity.runOnUiThread(() -> {
                ACTIVE_VERIFICATIONS.remove(entry.id, verification);
                Activity host = verification.activity;
                if (host == null) host = activity;
                if (verified) {
                    notifyReady(host, entry);
                    if (verification.paused.get()) {
                        if (usable(host) && !verification.forced) runContinue(verification.onContinue);
                        return;
                    }
                    if (foregroundUsable(host)) requestInstall(host, entry,
                        verification.forced, verification.onContinue);
                    return;
                }
                deleteQuietly(UpdatePackageStore.apkFile(host, entry.id),
                    "清理失效更新安装包");
                persistProgress(host, entry, "", "", 0L);
                if (verification.paused.get()) {
                    notifyFailure(host, "安装包校验未通过，已停止自动重试");
                    if (usable(host) && !verification.forced) runContinue(verification.onContinue);
                } else if (redownloadOnFailure && usable(host)) {
                    startDownload(host, entry, verification.forced, verification.onContinue);
                } else if (usable(host)) showFailure(host, entry.snapshot(),
                    verification.forced, verification.onContinue,
                    "安装包校验失败，已从历史中移除，请重新下载。");
            });
        }, "update-package-verifier");
        verifier.start();
    }

    private static void verifyCompletePartThenInstall(
        Activity activity,
        UpdatePackageStore.Entry entry,
        boolean forced,
        Runnable onContinue
    ) {
        VerificationSession verification = new VerificationSession(
            activity, entry, forced, onContinue);
        DownloadSession activeDownload;
        VerificationSession activeVerification;
        synchronized (OPERATION_LOCK) {
            activeDownload = ACTIVE_DOWNLOADS.get(entry.id);
            activeVerification = ACTIVE_VERIFICATIONS.get(entry.id);
            if (activeDownload == null && activeVerification == null) {
                ACTIVE_VERIFICATIONS.put(entry.id, verification);
            } else if (activeDownload != null) {
                activeDownload.attach(activity, forced, onContinue);
            } else {
                activeVerification.attach(activity, forced, onContinue);
            }
        }
        if (activeDownload != null || activeVerification != null) {
            showAlreadyDownloading(activity, forced, onContinue);
            return;
        }
        Thread verifier = new Thread(() -> {
            String failure;
            try {
                failure = verifyAndPromote(activity, entry);
            } catch (RuntimeException exception) {
                CrashReporter.record("提交完整更新分片", exception);
                failure = "无法校验已下载的安装包。";
            }
            String result = failure;
            activity.runOnUiThread(() -> {
                ACTIVE_VERIFICATIONS.remove(entry.id, verification);
                Activity host = verification.activity;
                if (host == null) host = activity;
                if (result == null) {
                    UpdatePackageStore.Entry complete = UpdatePackageStore.find(host, entry.id);
                    UpdatePackageStore.Entry ready = complete == null ? entry : complete;
                    notifyReady(host, ready);
                    if (verification.paused.get()) {
                        if (usable(host) && !verification.forced) runContinue(verification.onContinue);
                    } else if (foregroundUsable(host)) {
                        requestInstall(host, ready, verification.forced, verification.onContinue);
                    }
                } else if (verification.paused.get()) {
                    notifyFailure(host, "安装包校验已暂停，可稍后继续下载");
                    if (usable(host) && !verification.forced) runContinue(verification.onContinue);
                } else if (usable(host)) {
                    startDownload(host, entry, verification.forced, verification.onContinue);
                }
            });
        }, "update-part-verifier");
        verifier.start();
    }

    private static void startDownload(
        Activity activity,
        UpdatePackageStore.Entry entry,
        boolean forced,
        Runnable onContinue
    ) {
        if (!usable(activity)) return;
        DownloadSession session = new DownloadSession(activity, entry, forced, onContinue);
        boolean downloadStarted;
        DownloadSession active;
        VerificationSession verification;
        synchronized (OPERATION_LOCK) {
            verification = ACTIVE_VERIFICATIONS.get(entry.id);
            downloadStarted = verification == null
                && ACTIVE_DOWNLOADS.putIfAbsent(entry.id, session) == null;
            active = ACTIVE_DOWNLOADS.get(entry.id);
            if (!downloadStarted && active == null && verification != null) {
                verification.attach(activity, forced, onContinue);
            }
        }
        if (!downloadStarted) {
            if (active != null) active.attach(activity, forced, onContinue);
            showAlreadyDownloading(activity, forced, onContinue);
            return;
        }
        if (!showProgress(session)) {
            ACTIVE_DOWNLOADS.remove(entry.id, session);
            return;
        }
        File part = UpdatePackageStore.partFile(activity, entry.id);
        long offset = UpdateResumePolicy.resumableOffset(part.isFile() ? part.length() : 0L,
            entry.expectedSize);
        if (part.isFile() && offset == 0L && part.length() > 0L) deleteQuietly(part, "重置无效更新分片");
        updateProgressUi(session, offset, entry.expectedSize);
        executeRequest(session, offset, true);
    }

    private static boolean showProgress(DownloadSession session) {
        Activity activity = session.activity;
        LinearLayout content = new LinearLayout(activity);
        content.setOrientation(LinearLayout.VERTICAL);
        int padding = Math.round(24f * activity.getResources().getDisplayMetrics().density);
        content.setPadding(padding, 0, padding, padding / 2);
        session.progressText = new TextView(activity);
        session.progressText.setText("正在连接下载服务器…");
        session.progressText.setGravity(Gravity.CENTER_HORIZONTAL);
        session.progressBar = new ProgressBar(activity, null, android.R.attr.progressBarStyleHorizontal);
        session.progressBar.setIndeterminate(true);
        session.progressBar.setMax(100);
        content.addView(session.progressText, new LinearLayout.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));
        LinearLayout.LayoutParams params = new LinearLayout.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT);
        params.topMargin = padding / 2;
        content.addView(session.progressBar, params);
        session.progressDialog = new YiyunyingDialogBuilder(activity)
            .setTitle("正在下载更新")
            .setView(content)
            .setCancelable(false)
            .setNegativeButton("暂停下载", null)
            .create();
        session.progressDialog.setCanceledOnTouchOutside(false);
        ensureNotificationChannel(activity);
        try {
            session.progressDialog.show();
            session.progressDialog.getButton(AlertDialog.BUTTON_NEGATIVE).setOnClickListener(view -> {
                if (!session.paused.compareAndSet(false, true)) return;
                Call call = session.call;
                if (call != null) call.cancel();
                else finishPaused(session);
            });
            return true;
        } catch (RuntimeException | LinkageError exception) {
            CrashReporter.record("显示更新下载进度", exception);
            notifyFailure(activity, "无法显示下载进度，请稍后重试");
            if (!session.forced) runContinue(session.onContinue);
            return false;
        }
    }

    private static void executeRequest(DownloadSession session, long offset, boolean allowCleanRetry) {
        if (session.paused.get()) {
            finishPaused(session);
            return;
        }
        UpdatePackageStore.Entry latest = UpdatePackageStore.find(session.activity, session.entry.id);
        if (latest == null) {
            finishFailure(session, "安装包记录已被删除。", false);
            return;
        }
        Call call = UPDATE_HTTP_CLIENT.newCall(UpdateRequestPolicy.build(
            latest.downloadUrl, offset, latest.etag, latest.lastModified));
        session.call = call;
        if (session.paused.get()) {
            call.cancel();
            finishPaused(session);
            return;
        }
        call.enqueue(new Callback() {
            @Override public void onFailure(Call ignored, IOException exception) {
                persistCurrentProgress(session);
                if (session.paused.get() || call.isCanceled()) finishPaused(session);
                else finishFailure(session, "下载更新失败，已保存当前进度，请检查网络后继续。", false);
            }

            @Override public void onResponse(Call ignored, Response response) {
                handleResponse(session, response, offset, allowCleanRetry);
            }
        });
    }

    private static void handleResponse(
        DownloadSession session,
        Response response,
        long requestedOffset,
        boolean allowCleanRetry
    ) {
        try (Response safeResponse = response) {
            String finalUrl = safeResponse.request().url().toString();
            if (!UpdateTransportPolicy.allows(
                session.entry.downloadUrl, finalUrl, BuildConfig.ALLOW_HTTP_ENDPOINTS)) {
                finishFailure(session, "下载地址发生了不安全的协议降级，已阻止更新。", false);
                return;
            }
            ResponseBody body = safeResponse.body();
            long contentLength = body == null ? -1L : body.contentLength();
            UpdateResumePolicy.Decision decision = UpdateResumePolicy.decide(
                requestedOffset,
                session.entry.expectedSize,
                safeResponse.code(),
                safeResponse.header("Content-Range"),
                contentLength
            );
            if (decision.action == UpdateResumePolicy.Action.FAIL) {
                finishFailure(session, decision.reason + "。", false);
                return;
            }
            if (decision.action == UpdateResumePolicy.Action.VERIFY_LOCAL) {
                completeFromPart(session);
                return;
            }
            if (decision.action == UpdateResumePolicy.Action.RESTART && safeResponse.code() == 416) {
                if (!allowCleanRetry) {
                    finishFailure(session, "服务器无法接受重新下载请求。", false);
                    return;
                }
                resetPartial(session);
                executeRequest(session, 0L, false);
                return;
            }
            if (body == null) {
                finishFailure(session, "下载服务器没有返回安装包内容。", false);
                return;
            }
            boolean append = decision.action == UpdateResumePolicy.Action.APPEND;
            long start = append ? requestedOffset : 0L;
            if (!append && contentLength >= 0L && contentLength != session.entry.expectedSize) {
                finishFailure(session, "下载服务器返回的安装包大小与更新信息不一致。", false);
                return;
            }
            String etag = value(safeResponse.header("ETag"));
            String lastModified = value(safeResponse.header("Last-Modified"));
            persistProgress(session.activity, session.entry, etag, lastModified, start);
            String failure = writeBody(session, body, start, append, etag, lastModified);
            if (failure != null) {
                finishFailure(session, failure, session.paused.get());
                return;
            }
            completeFromPart(session);
        } catch (IOException exception) {
            persistCurrentProgress(session);
            if (session.paused.get()) finishPaused(session);
            else finishFailure(session, "保存安装包失败，当前进度已保留，请检查存储空间。", false);
        }
    }

    private static String writeBody(
        DownloadSession session,
        ResponseBody body,
        long start,
        boolean append,
        String etag,
        String lastModified
    ) throws IOException {
        File part = UpdatePackageStore.partFile(session.activity, session.entry.id);
        final long[] lastUiAt = {0L};
        final long[] lastStoreAt = {0L};
        long total;
        try {
            total = UpdateDownloadIo.copy(body.byteStream(), part, append, start,
                session.entry.expectedSize, session.paused::get, downloaded -> {
                    long now = android.os.SystemClock.elapsedRealtime();
                    if (now - lastUiAt[0] >= 180L) {
                        updateProgressUi(session, downloaded, session.entry.expectedSize);
                        lastUiAt[0] = now;
                    }
                    if (now - lastStoreAt[0] >= 1000L) {
                        persistProgress(session.activity, session.entry, etag, lastModified, downloaded);
                        lastStoreAt[0] = now;
                    }
                });
        } catch (UpdateDownloadIo.SizeLimitException exception) {
            deleteQuietly(part, "清理越界更新下载");
            persistProgress(session.activity, session.entry, "", "", 0L);
            return "安装包超过预期大小，已阻止安装。";
        }
        persistProgress(session.activity, session.entry, etag, lastModified, total);
        updateProgressUi(session, total, session.entry.expectedSize);
        if (total < session.entry.expectedSize) {
            return "下载连接提前结束，已保存当前进度，可继续下载。";
        }
        return null;
    }

    private static void completeFromPart(DownloadSession session) {
        if (session.paused.get()) {
            finishPaused(session);
            return;
        }
        String failure = verifyAndPromote(session.activity, session.entry);
        if (failure != null) {
            finishFailure(session, failure, false);
            return;
        }
        UpdatePackageStore.Entry complete = UpdatePackageStore.find(session.activity, session.entry.id);
        UpdatePackageStore.Entry ready = complete == null ? session.entry : complete;
        if (session.paused.get()) finishReadyWithoutInstall(session, ready);
        else finishSuccess(session, ready);
    }

    private static String verifyAndPromote(Activity activity, UpdatePackageStore.Entry entry) {
        File part = UpdatePackageStore.partFile(activity, entry.id);
        if (!verifiedPackageMatches(activity, part, entry)) {
            deleteQuietly(part, "清理校验失败的更新分片");
            persistProgress(activity, entry, "", "", 0L);
            return "安装包完整性、身份或签名校验失败，已阻止安装。";
        }
        File apk = UpdatePackageStore.apkFile(activity, entry.id);
        if (apk.exists() && !apk.delete()) return "无法替换旧的更新安装包。";
        if (!part.renameTo(apk)) return "无法提交已校验的安装包。";
        try {
            UpdatePackageStore.markComplete(activity, entry.id);
        } catch (IllegalArgumentException | IllegalStateException exception) {
            return exception.getMessage() == null ? "无法保存安装包完成状态。" : exception.getMessage();
        }
        return null;
    }

    private static boolean verifiedPackageMatches(
        Activity activity,
        File apk,
        UpdatePackageStore.Entry entry
    ) {
        if (activity == null || apk == null || entry == null || !apk.isFile()) return false;
        try {
            PackageInfo archive = archiveInfo(activity, apk);
            String archivePackage = archive == null || archive.packageName == null ? "" : archive.packageName;
            boolean metadataMatches = UpdatePackagePolicy.matches(
                entry.packageName,
                entry.versionCode,
                entry.expectedSize,
                entry.sha256,
                archivePackage,
                archiveVersionCode(archive),
                apk.length(),
                sha256(apk)
            );
            return metadataMatches && signingCertificatesMatch(activity, archive);
        } catch (IOException | PackageManager.NameNotFoundException | RuntimeException exception) {
            CrashReporter.record("校验已下载更新", exception);
            return false;
        }
    }

    private static PackageInfo archiveInfo(Activity activity, File apk) {
        PackageManager manager = activity.getPackageManager();
        if (Build.VERSION.SDK_INT >= 33) {
            return manager.getPackageArchiveInfo(apk.getAbsolutePath(),
                PackageManager.PackageInfoFlags.of(PackageManager.GET_SIGNING_CERTIFICATES));
        }
        //noinspection deprecation
        return manager.getPackageArchiveInfo(apk.getAbsolutePath(), Build.VERSION.SDK_INT >= 28
            ? PackageManager.GET_SIGNING_CERTIFICATES : PackageManager.GET_SIGNATURES);
    }

    private static PackageInfo installedInfo(Activity activity) throws PackageManager.NameNotFoundException {
        PackageManager manager = activity.getPackageManager();
        if (Build.VERSION.SDK_INT >= 33) {
            return manager.getPackageInfo(activity.getPackageName(),
                PackageManager.PackageInfoFlags.of(PackageManager.GET_SIGNING_CERTIFICATES));
        }
        //noinspection deprecation
        return manager.getPackageInfo(activity.getPackageName(), Build.VERSION.SDK_INT >= 28
            ? PackageManager.GET_SIGNING_CERTIFICATES : PackageManager.GET_SIGNATURES);
    }

    private static boolean signingCertificatesMatch(Activity activity, PackageInfo archive)
        throws PackageManager.NameNotFoundException, IOException {
        Set<String> installedCurrent = currentSignerHashes(installedInfo(activity));
        Set<String> candidateCurrent = currentSignerHashes(archive);
        Set<String> candidateHistory = signingHistoryHashes(archive);
        return UpdateSigningPolicy.allows(installedCurrent, candidateCurrent, candidateHistory);
    }

    private static Set<String> currentSignerHashes(PackageInfo info) throws IOException {
        Set<String> hashes = new HashSet<>();
        if (info == null) return hashes;
        List<Signature> signatures = new ArrayList<>();
        if (Build.VERSION.SDK_INT >= 28 && info.signingInfo != null) {
            Signature[] values = info.signingInfo.getApkContentsSigners();
            if (values != null) java.util.Collections.addAll(signatures, values);
        } else {
            //noinspection deprecation
            if (info.signatures != null) java.util.Collections.addAll(signatures, info.signatures);
        }
        for (Signature signature : signatures) {
            if (signature != null) hashes.add(sha256(signature.toByteArray()));
        }
        return hashes;
    }

    private static Set<String> signingHistoryHashes(PackageInfo info) throws IOException {
        if (Build.VERSION.SDK_INT < 28 || info == null || info.signingInfo == null
            || info.signingInfo.hasMultipleSigners()) {
            return currentSignerHashes(info);
        }
        Set<String> hashes = new HashSet<>();
        Signature[] history = info.signingInfo.getSigningCertificateHistory();
        if (history != null) {
            for (Signature signature : history) {
                if (signature != null) hashes.add(sha256(signature.toByteArray()));
            }
        }
        return hashes;
    }

    private static long archiveVersionCode(PackageInfo info) {
        if (info == null) return 0L;
        if (Build.VERSION.SDK_INT >= 28) return info.getLongVersionCode();
        //noinspection deprecation
        return info.versionCode;
    }

    private static String sha256(File file) throws IOException {
        MessageDigest digest = digest();
        byte[] buffer = new byte[64 * 1024];
        try (InputStream input = new FileInputStream(file)) {
            int read;
            while ((read = input.read(buffer)) != -1) digest.update(buffer, 0, read);
        }
        return hex(digest.digest());
    }

    private static String sha256(byte[] value) throws IOException {
        MessageDigest digest = digest();
        digest.update(value == null ? new byte[0] : value);
        return hex(digest.digest());
    }

    private static MessageDigest digest() throws IOException {
        try {
            return MessageDigest.getInstance("SHA-256");
        } catch (NoSuchAlgorithmException exception) {
            throw new IOException("SHA-256 unavailable", exception);
        }
    }

    private static void requestInstall(
        Activity activity,
        UpdatePackageStore.Entry entry,
        boolean forced,
        Runnable onContinue
    ) {
        if (!usable(activity) || entry == null || !entry.hasApk) return;
        if (Build.VERSION.SDK_INT >= 26 && !activity.getPackageManager().canRequestPackageInstalls()) {
            try {
                new YiyunyingDialogBuilder(activity)
                    .setTitle("允许安装更新")
                    .setMessage("请在系统设置中允许易运盈安装更新；返回软件后会自动继续，不需要再次下载或点击。")
                    .setCancelable(!forced)
                    .setPositiveButton("打开设置", (dialog, which) -> {
                        if (!usable(activity)) return;
                        try {
                            UpdatePackageStore.markPermissionPending(activity, entry.id, forced);
                            Intent intent = new Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES,
                                Uri.parse("package:" + activity.getPackageName()));
                            if (intent.resolveActivity(activity.getPackageManager()) == null) {
                                throw new IllegalStateException("系统没有安装权限设置入口");
                            }
                            activity.startActivity(intent);
                        } catch (RuntimeException exception) {
                            CrashReporter.record("打开更新安装权限", exception);
                            try { UpdatePackageStore.markComplete(activity, entry.id); }
                            catch (RuntimeException ignored) { }
                            showFailure(activity, entry.snapshot(), forced, onContinue,
                                "无法打开安装权限设置。");
                        }
                    })
                    .setNegativeButton(forced ? "退出" : "稍后", (dialog, which) -> {
                        try { UpdatePackageStore.markComplete(activity, entry.id); }
                        catch (RuntimeException ignored) { }
                        if (!usable(activity)) return;
                        if (forced) activity.finishAffinity(); else runContinue(onContinue);
                    })
                    .show();
            } catch (RuntimeException | LinkageError exception) {
                CrashReporter.record("显示更新安装权限提示", exception);
                try { UpdatePackageStore.markComplete(activity, entry.id); }
                catch (RuntimeException ignored) { }
                notifyFailure(activity, "无法显示安装权限提示，安装包已保留。" );
                if (forced) activity.finishAffinity(); else runContinue(onContinue);
            }
            return;
        }
        File apk = UpdatePackageStore.apkFile(activity, entry.id);
        Uri content;
        try {
            content = FileProvider.getUriForFile(activity,
                activity.getPackageName() + ".capture-files", apk);
        } catch (RuntimeException exception) {
            CrashReporter.record("准备更新安装包", exception);
            try { UpdatePackageStore.markComplete(activity, entry.id); }
            catch (RuntimeException ignored) { }
            showFailure(activity, entry.snapshot(), forced, onContinue, "无法准备安装包。" );
            return;
        }
        dispatchInstall(activity, entry, content, forced, onContinue);
    }

    static void dispatchInstall(
        Activity activity,
        UpdatePackageStore.Entry entry,
        Uri content,
        boolean forced,
        Runnable onContinue
    ) {
        try {
            UpdatePackageStore.markInstallRequested(activity, entry.id);
            activity.startActivity(installIntent(content));
            if (!forced) runContinue(onContinue);
        } catch (RuntimeException exception) {
            try { UpdatePackageStore.markComplete(activity, entry.id); }
            catch (RuntimeException ignored) { }
            showFailure(activity, entry.snapshot(), forced, onContinue, "系统没有可用的安装器。");
        }
    }

    static Intent installIntent(Uri content) {
        return new Intent(Intent.ACTION_VIEW)
            .setDataAndType(content, "application/vnd.android.package-archive")
            .addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION | Intent.FLAG_ACTIVITY_NEW_TASK);
    }

    static OkHttpClient updateHttpClientForTest() {
        return UPDATE_HTTP_CLIENT;
    }

    private static void finishSuccess(DownloadSession session, UpdatePackageStore.Entry entry) {
        if (!session.finished.compareAndSet(false, true)) return;
        notifyReady(session.activity, entry);
        session.activity.runOnUiThread(() -> {
            ACTIVE_DOWNLOADS.remove(session.entry.id, session);
            safeDismiss(session.progressDialog);
            if (session.paused.get()) {
                if (!usable(session.activity)) return;
                if (session.forced) {
                    showFailure(session.activity, entry.snapshot(), true, session.onContinue,
                        "安装包已下载并校验通过；自动安装已暂停，可点击继续安装。");
                } else {
                    runContinue(session.onContinue);
                }
                return;
            }
            if (foregroundUsable(session.activity)) requestInstall(
                session.activity, entry, session.forced, session.onContinue);
        });
    }

    private static void finishReadyWithoutInstall(
        DownloadSession session,
        UpdatePackageStore.Entry entry
    ) {
        if (!session.finished.compareAndSet(false, true)) return;
        notifyReady(session.activity, entry);
        session.activity.runOnUiThread(() -> {
            ACTIVE_DOWNLOADS.remove(session.entry.id, session);
            safeDismiss(session.progressDialog);
            if (!usable(session.activity)) return;
            if (session.forced) {
                showFailure(session.activity, entry.snapshot(), true, session.onContinue,
                    "安装包已下载并校验通过；自动安装已暂停，可点击继续安装。");
            } else {
                runContinue(session.onContinue);
            }
        });
    }

    private static void finishPaused(DownloadSession session) {
        if (!session.finished.compareAndSet(false, true)) return;
        persistCurrentProgress(session);
        notifyFailure(session.activity, "下载已暂停，当前进度已保存");
        session.activity.runOnUiThread(() -> {
            ACTIVE_DOWNLOADS.remove(session.entry.id, session);
            safeDismiss(session.progressDialog);
            if (!usable(session.activity)) return;
            if (session.forced) showFailure(session.activity, session.entry.snapshot(), true,
                session.onContinue, "下载已暂停，当前进度已保存，可继续下载。");
            else runContinue(session.onContinue);
        });
    }

    private static void finishFailure(DownloadSession session, String message, boolean paused) {
        if (paused) {
            finishPaused(session);
            return;
        }
        if (!session.finished.compareAndSet(false, true)) return;
        persistCurrentProgress(session);
        notifyFailure(session.activity, message);
        session.activity.runOnUiThread(() -> {
            ACTIVE_DOWNLOADS.remove(session.entry.id, session);
            safeDismiss(session.progressDialog);
            if (usable(session.activity)) showFailure(session.activity, session.entry.snapshot(),
                session.forced, session.onContinue, message);
        });
    }

    private static void resetPartial(DownloadSession session) {
        File part = UpdatePackageStore.partFile(session.activity, session.entry.id);
        deleteQuietly(part, "重置更新下载分片");
        persistProgress(session.activity, session.entry, "", "", 0L);
        updateProgressUi(session, 0L, session.entry.expectedSize);
    }

    private static void persistCurrentProgress(DownloadSession session) {
        UpdatePackageStore.Entry latest = UpdatePackageStore.find(session.activity, session.entry.id);
        String etag = latest == null ? "" : latest.etag;
        String modified = latest == null ? "" : latest.lastModified;
        File part = UpdatePackageStore.partFile(session.activity, session.entry.id);
        persistProgress(session.activity, session.entry, etag, modified,
            part.isFile() ? part.length() : 0L);
    }

    private static void persistProgress(
        Activity activity,
        UpdatePackageStore.Entry entry,
        String etag,
        String lastModified,
        long downloaded
    ) {
        try {
            UpdatePackageStore.updateValidators(activity, entry.id, etag, lastModified, downloaded);
        } catch (RuntimeException exception) {
            CrashReporter.record("保存更新下载进度", exception);
        }
    }

    private static void updateProgressUi(DownloadSession session, long downloaded, long total) {
        notifyProgress(session.activity, downloaded, total);
        session.activity.runOnUiThread(() -> {
            if (!usable(session.activity) || session.progressBar == null || session.progressText == null) return;
            if (total <= 0L) {
                session.progressBar.setIndeterminate(true);
                session.progressText.setText("已下载 " + readableBytes(downloaded));
                return;
            }
            int percent = (int) Math.min(100L, downloaded * 100L / total);
            session.progressBar.setIndeterminate(false);
            session.progressBar.setProgress(percent);
            String prefix = downloaded > 0L && downloaded < total ? "可续传 · " : "";
            session.progressText.setText(prefix + "已下载 " + percent + "%  ·  "
                + readableBytes(downloaded) + " / " + readableBytes(total));
        });
    }

    private static void showAlreadyDownloading(Activity activity, boolean forced, Runnable onContinue) {
        try {
            new YiyunyingDialogBuilder(activity)
                .setTitle("更新正在下载")
                .setMessage("同一安装包已有一个下载任务，进度会继续保留并显示在通知栏。")
                .setPositiveButton("知道了", (dialog, which) -> {
                    if (!forced) runContinue(onContinue);
                })
                .setCancelable(!forced)
                .show();
        } catch (RuntimeException | LinkageError exception) {
            if (!forced) runContinue(onContinue);
        }
    }

    private static void showFailure(
        Activity activity,
        JsonObject update,
        boolean forced,
        Runnable onContinue,
        String message
    ) {
        notifyFailure(activity, message);
        if (!usable(activity)) return;
        JsonObject snapshot = update == null ? new JsonObject() : update.deepCopy();
        com.google.android.material.dialog.MaterialAlertDialogBuilder builder =
            new YiyunyingDialogBuilder(activity)
                .setTitle("更新未完成")
                .setMessage(message == null || message.trim().isEmpty()
                    ? "更新暂时无法完成，请稍后重试。" : message)
                .setCancelable(!forced);
        if (snapshot.has("download_url")) {
            builder.setPositiveButton("继续下载", (dialog, which) ->
                install(activity, snapshot, forced, onContinue));
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
        if (activity == null || Build.VERSION.SDK_INT < 26) return;
        NotificationManager manager = activity.getSystemService(NotificationManager.class);
        if (manager == null) return;
        NotificationChannel channel = new NotificationChannel(
            UPDATE_CHANNEL, "软件更新", NotificationManager.IMPORTANCE_LOW);
        channel.setDescription("显示软件更新下载进度和安装状态");
        channel.setSound(null, null);
        manager.createNotificationChannel(channel);
    }

    private static void notifyProgress(Activity activity, long downloaded, long total) {
        if (activity == null) return;
        ensureNotificationChannel(activity);
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

    private static void notifyReady(Activity activity, UpdatePackageStore.Entry entry) {
        if (activity == null || entry == null) return;
        ensureNotificationChannel(activity);
        NotificationCompat.Builder builder = notificationBuilder(activity)
            .setContentTitle("更新已下载")
            .setContentText("安装包校验通过，点击继续安装")
            .setOngoing(false)
            .setAutoCancel(true)
            .setProgress(0, 0, false);
        try {
            PendingIntent install = PendingIntent.getActivity(activity, UPDATE_NOTIFICATION_ID,
                UpdatePackageHistoryActivity.intent(activity, entry.id),
                PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);
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

    private static boolean foregroundUsable(Activity activity) {
        if (!usable(activity)) return false;
        if (!(activity instanceof LifecycleOwner)) return true;
        return ((LifecycleOwner) activity).getLifecycle().getCurrentState()
            .isAtLeast(Lifecycle.State.RESUMED);
    }

    private static void safeDismiss(AlertDialog dialog) {
        if (dialog == null || !dialog.isShowing()) return;
        try { dialog.dismiss(); }
        catch (RuntimeException ignored) { }
    }

    private static void runContinue(Runnable onContinue) {
        if (onContinue == null) return;
        try { onContinue.run(); }
        catch (RuntimeException | LinkageError exception) {
            CrashReporter.record("继续进入应用", exception);
        }
    }

    private static void deleteQuietly(File file, String area) {
        if (file != null && file.exists() && !file.delete()) {
            CrashReporter.record(area, new IOException(file.getAbsolutePath()));
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

    private static String value(String value) {
        return value == null ? "" : value.trim();
    }

    private static final class DownloadSession {
        volatile Activity activity;
        final UpdatePackageStore.Entry entry;
        volatile boolean forced;
        volatile Runnable onContinue;
        final AtomicBoolean paused = new AtomicBoolean();
        final AtomicBoolean finished = new AtomicBoolean();
        volatile Call call;
        AlertDialog progressDialog;
        ProgressBar progressBar;
        TextView progressText;

        DownloadSession(
            Activity activity,
            UpdatePackageStore.Entry entry,
            boolean forced,
            Runnable onContinue
        ) {
            this.activity = activity;
            this.entry = entry;
            this.forced = forced;
            this.onContinue = onContinue;
        }

        void attach(Activity activity, boolean forced, Runnable onContinue) {
            Activity previous = this.activity;
            if (activity != null && activity != previous) {
                this.activity = activity;
                AlertDialog oldDialog = progressDialog;
                progressDialog = null;
                progressBar = null;
                progressText = null;
                if (oldDialog != null && previous != null) {
                    previous.runOnUiThread(() -> safeDismiss(oldDialog));
                }
            }
            this.forced = this.forced || forced;
            if (onContinue != null) this.onContinue = onContinue;
        }
    }

    private static final class VerificationSession {
        volatile Activity activity;
        final UpdatePackageStore.Entry entry;
        volatile boolean forced;
        volatile Runnable onContinue;
        final AtomicBoolean paused = new AtomicBoolean();

        VerificationSession(
            Activity activity,
            UpdatePackageStore.Entry entry,
            boolean forced,
            Runnable onContinue
        ) {
            this.activity = activity;
            this.entry = entry;
            this.forced = forced;
            this.onContinue = onContinue;
        }

        void attach(Activity activity, boolean forced, Runnable onContinue) {
            if (activity != null) this.activity = activity;
            this.forced = this.forced || forced;
            if (onContinue != null) this.onContinue = onContinue;
        }
    }
}
