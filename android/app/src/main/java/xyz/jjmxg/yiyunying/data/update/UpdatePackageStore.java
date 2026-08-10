package xyz.jjmxg.yiyunying.data.update;

import android.content.Context;
import android.content.SharedPreferences;
import android.content.pm.PackageInfo;
import android.content.pm.PackageManager;
import android.os.Build;

import com.google.gson.JsonElement;
import com.google.gson.JsonObject;
import com.google.gson.JsonParser;

import java.io.File;
import java.io.IOException;
import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;
import java.security.NoSuchAlgorithmException;
import java.util.ArrayList;
import java.util.Comparator;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Locale;
import java.util.Map;
import java.util.regex.Pattern;

/**
 * Persistent, package-scoped index for self-update APKs and resumable partial files.
 *
 * <p>The index deliberately does not use the signed-in account. Every Android edition has a
 * different application id and therefore a different sandbox, while an update may finish before
 * any account is restored after package replacement.</p>
 */
public final class UpdatePackageStore {
    public static final String STATE_PREPARED = "prepared";
    public static final String STATE_PARTIAL = "partial";
    public static final String STATE_COMPLETE = "complete";
    public static final String STATE_PERMISSION_PENDING = "permission_pending";
    public static final String STATE_INSTALL_REQUESTED = "install_requested";
    public static final String STATE_INSTALLED = "installed";
    public static final String STATE_INSTALLED_AUTO_DELETED = "installed_auto_deleted";
    public static final String STATE_CLEANUP_PENDING = "cleanup_pending";

    private static final String PREFERENCES = "update_packages_v1";
    private static final String KEY_ENTRIES = "entries";
    private static final String KEY_AUTO_DELETE = "auto_delete_after_install";
    private static final String DIRECTORY = "update_packages";
    private static final Pattern ID = Pattern.compile("^[a-f0-9]{64}$");
    private static final Pattern SHA256 = Pattern.compile("^[a-f0-9]{64}$");
    private static final Pattern PACKAGE = Pattern.compile(
        "^[A-Za-z][A-Za-z0-9_]*(?:\\.[A-Za-z][A-Za-z0-9_]*)+$");

    private UpdatePackageStore() { }

    public static synchronized Entry prepare(Context context, JsonObject update, String resolvedUrl) {
        Context app = app(context);
        JsonObject snapshot = update == null ? new JsonObject() : update.deepCopy();
        String packageName = value(snapshot, "package_name");
        if (packageName.isEmpty()) packageName = app.getPackageName();
        long versionCode = positiveLong(snapshot, "version_code");
        long expectedSize = positiveLong(snapshot, "size_bytes");
        String sha256 = value(snapshot, "sha256").toLowerCase(Locale.ROOT);
        String url = safe(resolvedUrl);
        if (!PACKAGE.matcher(packageName).matches() || !packageName.equals(app.getPackageName())) {
            throw new IllegalArgumentException("更新安装包应用标识与当前软件不匹配");
        }
        if (versionCode <= 0L) throw new IllegalArgumentException("更新版本号无效");
        if (expectedSize <= 0L) throw new IllegalArgumentException("更新安装包大小无效");
        if (!SHA256.matcher(sha256).matches()) {
            throw new IllegalArgumentException("更新安装包 SHA-256 无效");
        }
        if (url.isEmpty()) throw new IllegalArgumentException("更新安装包下载地址无效");

        String versionName = value(snapshot, "version_name");
        String id = identifier(packageName, versionCode, sha256);
        LinkedHashMap<String, JsonObject> records = records(app);
        JsonObject record = records.containsKey(id) ? records.get(id).deepCopy() : new JsonObject();
        long now = System.currentTimeMillis();
        record.addProperty("id", id);
        record.addProperty("package_name", packageName);
        record.addProperty("version_name", versionName);
        record.addProperty("version_code", versionCode);
        record.addProperty("expected_size", expectedSize);
        record.addProperty("sha256", sha256);
        record.addProperty("download_url", url);
        record.addProperty("forced", bool(snapshot, "force_update")
            || bool(snapshot, "forced") || bool(record, "forced"));
        if (number(record, "created_at") <= 0L) record.addProperty("created_at", now);

        snapshot.addProperty("package_name", packageName);
        snapshot.addProperty("version_name", versionName);
        snapshot.addProperty("version_code", versionCode);
        snapshot.addProperty("size_bytes", expectedSize);
        snapshot.addProperty("sha256", sha256);
        snapshot.addProperty("download_url", url);
        record.add("snapshot", snapshot);

        File apk = apkFile(app, id);
        File part = partFile(app, id);
        String previousState = value(record, "state");
        if (apk.isFile()) {
            if (!preservesCompletedPayloadState(previousState)) {
                record.addProperty("state", STATE_COMPLETE);
            }
            record.addProperty("downloaded_bytes", apk.length());
        } else if (part.isFile() && part.length() > 0L) {
            record.addProperty("state", STATE_PARTIAL);
            record.addProperty("downloaded_bytes", part.length());
        } else if (value(record, "state").isEmpty()
            || STATE_INSTALLED_AUTO_DELETED.equals(value(record, "state"))) {
            record.addProperty("state", STATE_PREPARED);
            record.addProperty("downloaded_bytes", 0L);
        }
        records.put(id, record);
        save(app, records, false);
        return entry(app, record);
    }

    private static boolean preservesCompletedPayloadState(String state) {
        return STATE_PERMISSION_PENDING.equals(state)
            || STATE_INSTALL_REQUESTED.equals(state)
            || STATE_INSTALLED.equals(state)
            || STATE_CLEANUP_PENDING.equals(state);
    }

    public static synchronized Entry find(Context context, String id) {
        if (!validId(id)) return null;
        JsonObject record = records(app(context)).get(id);
        return record == null ? null : entry(app(context), record);
    }

    public static synchronized List<Entry> list(Context context) {
        Context app = app(context);
        List<Entry> result = new ArrayList<>();
        for (JsonObject record : records(app).values()) {
            try {
                result.add(entry(app, record));
            } catch (IllegalArgumentException ignored) {
                // A malformed local record must not escape the controlled update directory.
            }
        }
        result.sort(Comparator.comparingLong(UpdatePackageStore::sortTime).reversed());
        return result;
    }

    public static synchronized Entry newestPermissionPending(Context context) {
        Entry newest = null;
        for (Entry item : list(context)) {
            if (!STATE_PERMISSION_PENDING.equals(item.state)) continue;
            if (newest == null || item.updatedAt > newest.updatedAt) newest = item;
        }
        return newest;
    }

    public static File partFile(Context context, String id) {
        return controlledFile(context, id, ".part");
    }

    public static File apkFile(Context context, String id) {
        return controlledFile(context, id, ".apk");
    }

    public static synchronized Entry updateValidators(
        Context context,
        String id,
        String etag,
        String lastModified,
        long downloadedBytes
    ) {
        Context app = app(context);
        LinkedHashMap<String, JsonObject> records = records(app);
        JsonObject record = require(records, id).deepCopy();
        record.addProperty("etag", safe(etag));
        record.addProperty("last_modified", safe(lastModified));
        record.addProperty("downloaded_bytes", Math.max(0L, downloadedBytes));
        record.addProperty("state", downloadedBytes > 0L ? STATE_PARTIAL : STATE_PREPARED);
        record.addProperty("updated_at", System.currentTimeMillis());
        records.put(id, record);
        save(app, records, false);
        return entry(app, record);
    }

    public static synchronized Entry markComplete(Context context, String id) {
        Context app = app(context);
        LinkedHashMap<String, JsonObject> records = records(app);
        JsonObject record = require(records, id).deepCopy();
        File apk = apkFile(app, id);
        long expected = number(record, "expected_size");
        if (!apk.isFile() || apk.length() != expected) {
            throw new IllegalStateException("安装包尚未完整写入持久目录");
        }
        File part = partFile(app, id);
        if (part.exists() && !part.delete()) {
            throw new IllegalStateException("无法清理已完成安装包的下载分片");
        }
        long now = System.currentTimeMillis();
        record.addProperty("state", STATE_COMPLETE);
        record.addProperty("downloaded_bytes", apk.length());
        record.addProperty("completed_at", now);
        record.addProperty("updated_at", now);
        records.put(id, record);
        save(app, records, false);
        return entry(app, record);
    }

    public static synchronized Entry markPermissionPending(Context context, String id, boolean forced) {
        Context app = app(context);
        LinkedHashMap<String, JsonObject> records = records(app);
        JsonObject record = require(records, id).deepCopy();
        record.addProperty("state", STATE_PERMISSION_PENDING);
        record.addProperty("forced", forced);
        record.addProperty("updated_at", System.currentTimeMillis());
        records.put(id, record);
        save(app, records, true);
        return entry(app, record);
    }

    /** Persists install intent state synchronously before control is handed to another process. */
    public static synchronized Entry markInstallRequested(Context context, String id) {
        Context app = app(context);
        LinkedHashMap<String, JsonObject> records = records(app);
        JsonObject record = require(records, id).deepCopy();
        long now = System.currentTimeMillis();
        record.addProperty("state", STATE_INSTALL_REQUESTED);
        record.addProperty("install_requested_at", now);
        record.addProperty("installed_version_at_request", installedVersion(app));
        record.addProperty("delete_after_install", autoDeleteAfterInstall(app));
        record.addProperty("updated_at", now);
        records.put(id, record);
        save(app, records, true);
        return entry(app, record);
    }

    /**
     * Reconciles pending self-installs after ACTION_MY_PACKAGE_REPLACED or on a later UI start.
     * The operation is idempotent and never relies on a restored login session.
     */
    public static synchronized Summary reconcileInstalled(Context context) {
        return reconcileInstalled(context, false);
    }

    /** Reconciles a confirmed system package-replacement broadcast, including same-version installs. */
    public static synchronized Summary reconcileInstalledAfterReplacement(Context context) {
        return reconcileInstalled(context, true);
    }

    private static Summary reconcileInstalled(Context context, boolean replacementConfirmed) {
        Context app = app(context);
        long installedVersion = installedVersion(app);
        if (installedVersion <= 0L) return summary(app);
        LinkedHashMap<String, JsonObject> records = records(app);
        boolean changed = false;
        long now = System.currentTimeMillis();
        for (Map.Entry<String, JsonObject> item : records.entrySet()) {
            JsonObject record = item.getValue().deepCopy();
            String state = value(record, "state");
            long beforeInstall = number(record, "installed_version_at_request");
            if (!app.getPackageName().equals(value(record, "package_name"))
                || number(record, "install_requested_at") <= 0L
                || (!STATE_INSTALL_REQUESTED.equals(state) && !STATE_CLEANUP_PENDING.equals(state))
                || installedVersion < number(record, "version_code")
                || (!replacementConfirmed && installedVersion <= beforeInstall)) continue;

            boolean deleteAfterInstall = bool(record, "delete_after_install");
            record.addProperty("installed_at", number(record, "installed_at") > 0L
                ? number(record, "installed_at") : now);
            record.addProperty("updated_at", now);
            if (deleteAfterInstall) {
                boolean deleted = deletePayload(app, item.getKey());
                record.addProperty("state", deleted
                    ? STATE_INSTALLED_AUTO_DELETED : STATE_CLEANUP_PENDING);
                if (deleted) record.addProperty("downloaded_bytes", 0L);
            } else {
                record.addProperty("state", STATE_INSTALLED);
            }
            records.put(item.getKey(), record);
            changed = true;
        }
        if (changed) save(app, records, true);
        return summary(app);
    }

    /** Deletes both controlled payload files and their local history record. */
    public static synchronized boolean delete(Context context, String id) {
        if (!validId(id)) return false;
        Context app = app(context);
        if (!deletePayload(app, id)) return false;
        LinkedHashMap<String, JsonObject> records = records(app);
        boolean removed = records.remove(id) != null;
        if (removed) save(app, records, true);
        return removed;
    }

    /** Alias kept for callers that describe deletion as removal from the package library. */
    public static synchronized boolean remove(Context context, String id) {
        return delete(context, id);
    }

    public static synchronized int deleteAll(Context context) {
        int deleted = 0;
        for (Entry item : new ArrayList<>(list(context))) {
            if (delete(context, item.id)) deleted++;
        }
        return deleted;
    }

    public static boolean autoDeleteAfterInstall(Context context) {
        return preferences(app(context)).getBoolean(KEY_AUTO_DELETE, false);
    }

    public static boolean getAutoDeleteAfterInstall(Context context) {
        return autoDeleteAfterInstall(context);
    }

    public static void setAutoDeleteAfterInstall(Context context, boolean enabled) {
        preferences(app(context)).edit().putBoolean(KEY_AUTO_DELETE, enabled).apply();
    }

    public static synchronized Summary summary(Context context) {
        Context app = app(context);
        int partial = 0;
        int ready = 0;
        int installPending = 0;
        int autoDeleted = 0;
        int packages = 0;
        long totalBytes = 0L;
        List<Entry> entries = list(app);
        for (Entry entry : entries) {
            if (entry.hasPart || entry.hasApk) packages++;
            if (entry.hasPart) {
                partial++;
                totalBytes += partFile(app, entry.id).length();
            }
            if (entry.hasApk) {
                ready++;
                totalBytes += apkFile(app, entry.id).length();
            }
            if (STATE_PERMISSION_PENDING.equals(entry.state)
                || STATE_INSTALL_REQUESTED.equals(entry.state)) installPending++;
            if (STATE_INSTALLED_AUTO_DELETED.equals(entry.state)) autoDeleted++;
        }
        return new Summary(entries.size(), packages, partial, ready, installPending, autoDeleted, totalBytes);
    }

    private static Context app(Context context) {
        if (context == null) throw new IllegalArgumentException("context == null");
        Context application = context.getApplicationContext();
        return application == null ? context : application;
    }

    private static SharedPreferences preferences(Context context) {
        return context.getSharedPreferences(PREFERENCES, Context.MODE_PRIVATE);
    }

    private static LinkedHashMap<String, JsonObject> records(Context context) {
        LinkedHashMap<String, JsonObject> result = new LinkedHashMap<>();
        String raw = preferences(context).getString(KEY_ENTRIES, "{}");
        try {
            JsonElement parsed = JsonParser.parseString(raw == null ? "{}" : raw);
            if (!parsed.isJsonObject()) return result;
            for (Map.Entry<String, JsonElement> item : parsed.getAsJsonObject().entrySet()) {
                if (validId(item.getKey()) && item.getValue().isJsonObject()) {
                    result.put(item.getKey(), item.getValue().getAsJsonObject().deepCopy());
                }
            }
        } catch (RuntimeException ignored) { }
        return result;
    }

    private static void save(Context context, LinkedHashMap<String, JsonObject> records, boolean synchronous) {
        JsonObject root = new JsonObject();
        for (Map.Entry<String, JsonObject> item : records.entrySet()) {
            root.add(item.getKey(), item.getValue());
        }
        SharedPreferences.Editor editor = preferences(context).edit().putString(KEY_ENTRIES, root.toString());
        if (synchronous && !editor.commit()) {
            throw new IllegalStateException("无法保存安装包状态");
        }
        if (!synchronous) editor.apply();
    }

    private static JsonObject require(LinkedHashMap<String, JsonObject> records, String id) {
        if (!validId(id) || !records.containsKey(id)) {
            throw new IllegalArgumentException("找不到安装包记录");
        }
        return records.get(id);
    }

    private static Entry entry(Context context, JsonObject record) {
        String id = value(record, "id");
        if (!validId(id)) throw new IllegalArgumentException("安装包记录编号无效");
        File part = partFile(context, id);
        File apk = apkFile(context, id);
        boolean hasApk = apk.isFile();
        boolean hasPart = part.isFile() && part.length() > 0L;
        long bytes = hasApk ? apk.length() : (hasPart ? part.length() : number(record, "downloaded_bytes"));
        JsonObject snapshot = object(record, "snapshot");
        return new Entry(
            id,
            value(record, "package_name"),
            value(record, "version_name"),
            number(record, "version_code"),
            number(record, "expected_size"),
            value(record, "sha256"),
            value(record, "download_url"),
            snapshot,
            value(record, "etag"),
            value(record, "last_modified"),
            defaultValue(value(record, "state"), STATE_PREPARED),
            number(record, "created_at"),
            number(record, "updated_at"),
            number(record, "completed_at"),
            number(record, "install_requested_at"),
            number(record, "installed_version_at_request"),
            number(record, "installed_at"),
            bool(record, "forced"),
            bool(record, "delete_after_install"),
            hasPart,
            hasApk,
            Math.max(0L, bytes)
        );
    }

    private static long sortTime(Entry entry) {
        if (entry.installRequestedAt > 0L) return entry.installRequestedAt;
        if (entry.completedAt > 0L) return entry.completedAt;
        if (entry.updatedAt > 0L) return entry.updatedAt;
        return entry.createdAt;
    }

    private static File controlledFile(Context context, String id, String suffix) {
        if (!validId(id)) throw new IllegalArgumentException("安装包记录编号无效");
        Context app = app(context);
        File directory = new File(app.getFilesDir(), DIRECTORY);
        if (!directory.exists() && !directory.mkdirs() && !directory.isDirectory()) {
            throw new IllegalStateException("无法创建安装包目录");
        }
        File file = new File(directory, id + suffix);
        try {
            String root = directory.getCanonicalPath() + File.separator;
            String target = file.getCanonicalPath();
            if (!target.startsWith(root)) throw new IllegalArgumentException("安装包路径越界");
        } catch (IOException exception) {
            throw new IllegalStateException("无法解析安装包路径", exception);
        }
        return file;
    }

    private static boolean deletePayload(Context context, String id) {
        File part = partFile(context, id);
        File apk = apkFile(context, id);
        if (part.exists() && !part.delete()) return false;
        if (apk.exists() && !apk.delete()) return false;
        return !part.exists() && !apk.exists();
    }

    private static long installedVersion(Context context) {
        try {
            PackageManager manager = context.getPackageManager();
            PackageInfo info;
            if (Build.VERSION.SDK_INT >= 33) {
                info = manager.getPackageInfo(context.getPackageName(), PackageManager.PackageInfoFlags.of(0));
            } else {
                //noinspection deprecation
                info = manager.getPackageInfo(context.getPackageName(), 0);
            }
            if (Build.VERSION.SDK_INT >= 28) return info.getLongVersionCode();
            //noinspection deprecation
            return info.versionCode;
        } catch (PackageManager.NameNotFoundException | RuntimeException ignored) {
            return 0L;
        }
    }

    private static String identifier(String packageName, long versionCode, String sha256) {
        try {
            MessageDigest digest = MessageDigest.getInstance("SHA-256");
            byte[] value = digest.digest((packageName + "|" + versionCode + "|" + sha256)
                .getBytes(StandardCharsets.UTF_8));
            StringBuilder result = new StringBuilder(value.length * 2);
            for (byte item : value) result.append(String.format(Locale.ROOT, "%02x", item & 0xff));
            return result.toString();
        } catch (NoSuchAlgorithmException exception) {
            throw new IllegalStateException("SHA-256 unavailable", exception);
        }
    }

    private static boolean validId(String id) {
        return id != null && ID.matcher(id).matches();
    }

    private static String value(JsonObject object, String key) {
        if (object == null || !object.has(key) || object.get(key).isJsonNull()) return "";
        try { return safe(object.get(key).getAsString()); }
        catch (RuntimeException ignored) { return ""; }
    }

    private static long positiveLong(JsonObject object, String key) {
        return Math.max(0L, number(object, key));
    }

    private static long number(JsonObject object, String key) {
        if (object == null || !object.has(key) || object.get(key).isJsonNull()) return 0L;
        try { return object.get(key).getAsLong(); }
        catch (RuntimeException ignored) { return 0L; }
    }

    private static boolean bool(JsonObject object, String key) {
        if (object == null || !object.has(key) || object.get(key).isJsonNull()) return false;
        try { return object.get(key).getAsBoolean(); }
        catch (RuntimeException ignored) { return false; }
    }

    private static JsonObject object(JsonObject source, String key) {
        if (source == null || !source.has(key) || !source.get(key).isJsonObject()) return new JsonObject();
        return source.getAsJsonObject(key).deepCopy();
    }

    private static String safe(String value) {
        return value == null ? "" : value.trim();
    }

    private static String defaultValue(String value, String fallback) {
        return value == null || value.isEmpty() ? fallback : value;
    }

    public static final class Entry {
        public final String id;
        public final String packageName;
        public final String versionName;
        public final long versionCode;
        public final long expectedSize;
        public final String sha256;
        public final String downloadUrl;
        public final JsonObject snapshotJson;
        public final String etag;
        public final String lastModified;
        public final String state;
        public final long createdAt;
        public final long updatedAt;
        public final long completedAt;
        public final long installRequestedAt;
        public final long installedVersionAtRequest;
        public final long installedAt;
        public final boolean forced;
        public final boolean deleteAfterInstall;
        public final boolean hasPart;
        public final boolean hasApk;
        public final long bytes;

        private Entry(
            String id,
            String packageName,
            String versionName,
            long versionCode,
            long expectedSize,
            String sha256,
            String downloadUrl,
            JsonObject snapshotJson,
            String etag,
            String lastModified,
            String state,
            long createdAt,
            long updatedAt,
            long completedAt,
            long installRequestedAt,
            long installedVersionAtRequest,
            long installedAt,
            boolean forced,
            boolean deleteAfterInstall,
            boolean hasPart,
            boolean hasApk,
            long bytes
        ) {
            this.id = id;
            this.packageName = packageName;
            this.versionName = versionName;
            this.versionCode = versionCode;
            this.expectedSize = expectedSize;
            this.sha256 = sha256;
            this.downloadUrl = downloadUrl;
            this.snapshotJson = snapshotJson == null ? new JsonObject() : snapshotJson.deepCopy();
            this.etag = etag;
            this.lastModified = lastModified;
            this.state = state;
            this.createdAt = createdAt;
            this.updatedAt = updatedAt;
            this.completedAt = completedAt;
            this.installRequestedAt = installRequestedAt;
            this.installedVersionAtRequest = installedVersionAtRequest;
            this.installedAt = installedAt;
            this.forced = forced;
            this.deleteAfterInstall = deleteAfterInstall;
            this.hasPart = hasPart;
            this.hasApk = hasApk;
            this.bytes = bytes;
        }

        public JsonObject snapshot() {
            return snapshotJson.deepCopy();
        }
    }

    public static final class Summary {
        public final int recordCount;
        public final int packageCount;
        public final int partialCount;
        public final int readyCount;
        public final int installPendingCount;
        public final int autoDeletedCount;
        public final long totalBytes;

        private Summary(
            int recordCount,
            int packageCount,
            int partialCount,
            int readyCount,
            int installPendingCount,
            int autoDeletedCount,
            long totalBytes
        ) {
            this.recordCount = recordCount;
            this.packageCount = packageCount;
            this.partialCount = partialCount;
            this.readyCount = readyCount;
            this.installPendingCount = installPendingCount;
            this.autoDeletedCount = autoDeletedCount;
            this.totalBytes = totalBytes;
        }
    }
}
