package xyz.jjmxg.yiyunying.data.cache;

import android.app.DownloadManager;
import android.content.Context;
import android.net.Uri;

import java.io.File;
import java.util.ArrayList;
import java.util.Collections;
import java.util.LinkedHashSet;
import java.util.List;
import java.util.Locale;
import java.util.Set;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

import xyz.jjmxg.yiyunying.core.AppAccess;

/** Unified index and deletion coordinator for account-local offline content. */
public final class LocalCacheManager {
    private static final Pattern SOURCE = Pattern.compile("/(groups|chat-rooms|conversations|users|customer-service)/(\\d+)", Pattern.CASE_INSENSITIVE);
    private static volatile LocalCacheManager instance;

    private final Context context;
    private final LocalCacheIndex index;
    private final AutoCachePolicyStore policy;

    private LocalCacheManager(Context context) {
        this.context = context.getApplicationContext();
        index = new LocalCacheIndex(this.context);
        policy = new AutoCachePolicyStore(this.context);
    }

    public static LocalCacheManager get(Context context) {
        if (instance == null) {
            synchronized (LocalCacheManager.class) {
                if (instance == null) instance = new LocalCacheManager(context);
            }
        }
        return instance;
    }

    public AutoCachePolicyStore policy() { return policy; }

    public String accountKey() {
        try {
            return AppAccess.from(context).session().role().wireName() + "|"
                + AppAccess.from(context).session().actorId() + "|"
                + AppAccess.from(context).session().appKey();
        } catch (RuntimeException ignored) {
            return "guest|0|";
        }
    }

    public void registerApiCache(File file, String originKey, String resourceKey, String contentKind) {
        if (file == null || !file.isFile()) return;
        String category = AutoCachePolicyStore.categoryForContentKind(contentKind);
        if (!policy.accepts(category)) return;
        SourceInfo source = source(resourceKey);
        LocalCacheEntry entry = new LocalCacheEntry();
        entry.accountKey = accountKey();
        entry.sourceType = source.type;
        entry.sourceId = source.id;
        entry.sourceTitle = source.title;
        entry.category = category;
        entry.localPath = file.getAbsolutePath();
        entry.displayName = contentKind == null || contentKind.isEmpty() ? "离线内容" : contentKind;
        entry.mimeType = "application/json";
        entry.sizeBytes = file.length();
        entry.createdAtMs = file.lastModified() > 0 ? file.lastModified() : System.currentTimeMillis();
        entry.accessedAtMs = System.currentTimeMillis();
        entry.originKey = originKey;
        index.upsert(entry);
        enforceLimits();
    }

    public void registerDownload(long downloadId, String name, String url, String categoryHint) {
        String category = AutoCachePolicyStore.categoryForFile("", name, categoryHint);
        LocalCacheEntry entry = new LocalCacheEntry();
        entry.accountKey = accountKey();
        entry.category = category;
        entry.remoteUrl = safe(url);
        entry.displayName = empty(name) ? "已下载文件" : name;
        entry.externalDownloadId = downloadId;
        entry.createdAtMs = System.currentTimeMillis();
        entry.accessedAtMs = entry.createdAtMs;
        entry.protectedFromCleanup = true;
        entry.originKey = "download:" + downloadId;
        SourceInfo source = source(url);
        entry.sourceType = source.type;
        entry.sourceId = source.id;
        entry.sourceTitle = source.title;
        hydrateDownload(entry);
        index.upsert(entry);
        enforceLimits();
    }

    public List<LocalCacheEntry> list(LocalCacheFilter filter) {
        return index.query(accountKey(), filter);
    }

    public List<CacheSource> sources() {
        List<CacheSource> result = new ArrayList<>();
        for (LocalCacheIndex.Source source : index.sources(accountKey())) {
            result.add(new CacheSource(source.type, source.id, source.title, source.count));
        }
        return result;
    }

    public void touch(String originKey) {
        index.touch(accountKey(), originKey, System.currentTimeMillis());
    }

    public void protect(Set<Long> ids, boolean value) {
        index.setProtected(accountKey(), ids, value);
    }

    public int delete(Set<Long> ids, boolean includeProtected) {
        if (ids == null || ids.isEmpty()) return 0;
        LocalCacheFilter filter = new LocalCacheFilter();
        List<LocalCacheEntry> entries = list(filter);
        Set<Long> removed = new LinkedHashSet<>();
        for (LocalCacheEntry entry : entries) {
            if (!ids.contains(entry.id) || (entry.protectedFromCleanup && !includeProtected)) continue;
            deletePayload(entry);
            removed.add(entry.id);
        }
        index.deleteIds(accountKey(), removed);
        return removed.size();
    }

    public int clearUnprotected() {
        List<LocalCacheEntry> entries = list(new LocalCacheFilter());
        Set<Long> ids = new LinkedHashSet<>();
        for (LocalCacheEntry entry : entries) if (!entry.protectedFromCleanup) ids.add(entry.id);
        return delete(ids, false);
    }

    public long indexedBytes() {
        long total = 0L;
        for (LocalCacheEntry entry : list(new LocalCacheFilter())) {
            if (entry.localPath != null && !entry.localPath.isEmpty()) {
                File file = new File(entry.localPath);
                total += file.isFile() ? file.length() : Math.max(0L, entry.sizeBytes);
            } else {
                total += Math.max(0L, entry.sizeBytes);
            }
        }
        return total;
    }

    public void removeExternalDownload(long downloadId) {
        index.deleteByExternalId(accountKey(), downloadId);
    }

    public void enforceLimits() {
        List<LocalCacheEntry> entries = list(new LocalCacheFilter());
        if (entries.isEmpty()) return;
        long now = System.currentTimeMillis();
        long expiry = policy.retentionDays() <= 0 ? 0L : now - policy.retentionDays() * 86_400_000L;
        long total = 0L;
        for (LocalCacheEntry entry : entries) total += actualSize(entry);
        long maximum = policy.maxBytes();
        List<LocalCacheEntry> oldest = new ArrayList<>(entries);
        Collections.reverse(oldest);
        Set<Long> remove = new LinkedHashSet<>();
        for (LocalCacheEntry entry : oldest) {
            if (entry.protectedFromCleanup) continue;
            boolean expired = expiry > 0L && entry.createdAtMs < expiry;
            if (!expired && total <= maximum) continue;
            total -= actualSize(entry);
            remove.add(entry.id);
        }
        delete(remove, false);
    }

    private void hydrateDownload(LocalCacheEntry entry) {
        DownloadManager manager = (DownloadManager) context.getSystemService(Context.DOWNLOAD_SERVICE);
        if (manager == null || entry.externalDownloadId < 0L) return;
        try (android.database.Cursor cursor = manager.query(new DownloadManager.Query().setFilterById(entry.externalDownloadId))) {
            if (cursor == null || !cursor.moveToFirst()) return;
            int localIndex = cursor.getColumnIndex(DownloadManager.COLUMN_LOCAL_URI);
            int mediaIndex = cursor.getColumnIndex(DownloadManager.COLUMN_MEDIA_TYPE);
            int sizeIndex = cursor.getColumnIndex(DownloadManager.COLUMN_TOTAL_SIZE_BYTES);
            if (localIndex >= 0 && !cursor.isNull(localIndex)) {
                String uri = cursor.getString(localIndex);
                if (uri != null && uri.startsWith("file:")) entry.localPath = Uri.parse(uri).getPath();
            }
            if (mediaIndex >= 0 && !cursor.isNull(mediaIndex)) entry.mimeType = cursor.getString(mediaIndex);
            if (sizeIndex >= 0 && !cursor.isNull(sizeIndex)) entry.sizeBytes = Math.max(0L, cursor.getLong(sizeIndex));
            entry.category = AutoCachePolicyStore.categoryForFile(entry.mimeType, entry.displayName, entry.category);
        } catch (RuntimeException ignored) { }
    }

    private void deletePayload(LocalCacheEntry entry) {
        if (entry.externalDownloadId >= 0L) {
            DownloadManager manager = (DownloadManager) context.getSystemService(Context.DOWNLOAD_SERVICE);
            if (manager != null) manager.remove(entry.externalDownloadId);
        }
        if (!empty(entry.localPath)) {
            File file = new File(entry.localPath);
            if (file.isFile()) file.delete();
        }
    }

    private static long actualSize(LocalCacheEntry entry) {
        if (!empty(entry.localPath)) {
            File file = new File(entry.localPath);
            if (file.isFile()) return file.length();
        }
        return Math.max(0L, entry.sizeBytes);
    }

    private static SourceInfo source(String value) {
        String text = safe(value);
        Matcher matcher = SOURCE.matcher(text);
        if (!matcher.find()) return new SourceInfo("other", "", "其他来源");
        String raw = matcher.group(1).toLowerCase(Locale.ROOT);
        String id = matcher.group(2);
        if ("groups".equals(raw)) return new SourceInfo("group", id, "群聊 " + id);
        if ("chat-rooms".equals(raw)) return new SourceInfo("room", id, "聊天室 " + id);
        if ("users".equals(raw)) return new SourceInfo("friend", id, "联系人 " + id);
        if ("customer-service".equals(raw)) return new SourceInfo("service", id, "在线客服");
        return new SourceInfo("conversation", id, "会话 " + id);
    }

    private static String safe(String value) { return value == null ? "" : value; }
    private static boolean empty(String value) { return value == null || value.trim().isEmpty(); }

    private static final class SourceInfo {
        final String type;
        final String id;
        final String title;
        SourceInfo(String type, String id, String title) { this.type = type; this.id = id; this.title = title; }
    }

    public static final class CacheSource {
        public final String type;
        public final String id;
        public final String title;
        public final int count;
        CacheSource(String type, String id, String title, int count) {
            this.type = type; this.id = id; this.title = title; this.count = count;
        }
    }
}
