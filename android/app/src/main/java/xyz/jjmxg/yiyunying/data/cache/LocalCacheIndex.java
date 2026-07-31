package xyz.jjmxg.yiyunying.data.cache;

import android.content.ContentValues;
import android.content.Context;
import android.database.Cursor;
import android.database.sqlite.SQLiteDatabase;
import android.database.sqlite.SQLiteOpenHelper;
import android.text.TextUtils;

import java.util.ArrayList;
import java.util.Collections;
import java.util.List;
import java.util.Set;

final class LocalCacheIndex extends SQLiteOpenHelper {
    private static final String DATABASE = "yiyunying_local_cache.db";
    private static final int VERSION = 1;

    LocalCacheIndex(Context context) {
        super(context, DATABASE, null, VERSION);
    }

    @Override public void onCreate(SQLiteDatabase db) {
        db.execSQL("CREATE TABLE cache_entries ("
            + "id INTEGER PRIMARY KEY AUTOINCREMENT,"
            + "account_key TEXT NOT NULL,"
            + "source_type TEXT NOT NULL DEFAULT 'other',"
            + "source_id TEXT NOT NULL DEFAULT '',"
            + "source_title TEXT NOT NULL DEFAULT '其他来源',"
            + "category TEXT NOT NULL,"
            + "local_path TEXT NOT NULL DEFAULT '',"
            + "remote_url TEXT NOT NULL DEFAULT '',"
            + "display_name TEXT NOT NULL DEFAULT '缓存内容',"
            + "mime_type TEXT NOT NULL DEFAULT 'application/octet-stream',"
            + "size_bytes INTEGER NOT NULL DEFAULT 0,"
            + "created_at_ms INTEGER NOT NULL,"
            + "accessed_at_ms INTEGER NOT NULL,"
            + "protected INTEGER NOT NULL DEFAULT 0,"
            + "external_download_id INTEGER NOT NULL DEFAULT -1,"
            + "origin_key TEXT NOT NULL,"
            + "UNIQUE(account_key, origin_key)"
            + ")");
        db.execSQL("CREATE INDEX idx_cache_account_time ON cache_entries(account_key, created_at_ms DESC)");
        db.execSQL("CREATE INDEX idx_cache_account_category ON cache_entries(account_key, category)");
        db.execSQL("CREATE INDEX idx_cache_account_source ON cache_entries(account_key, source_type, source_id)");
        db.execSQL("CREATE INDEX idx_cache_account_protected ON cache_entries(account_key, protected)");
    }

    @Override public void onUpgrade(SQLiteDatabase db, int oldVersion, int newVersion) { }

    synchronized long upsert(LocalCacheEntry entry) {
        if (entry == null || empty(entry.accountKey) || empty(entry.originKey)) return -1L;
        SQLiteDatabase db = getWritableDatabase();
        db.beginTransaction();
        try {
            long existingId = -1L;
            int existingProtected = entry.protectedFromCleanup ? 1 : 0;
            long existingCreatedAt = 0L;
            try (Cursor cursor = db.query(
                "cache_entries",
                new String[] { "id", "protected", "created_at_ms" },
                "account_key = ? AND origin_key = ?",
                new String[] { entry.accountKey, entry.originKey },
                null,
                null,
                null,
                "1"
            )) {
                if (cursor.moveToFirst()) {
                    existingId = cursor.getLong(0);
                    existingProtected = cursor.getInt(1);
                    existingCreatedAt = cursor.getLong(2);
                }
            }

            ContentValues values = values(entry);
            values.put("protected", existingProtected);
            if (existingCreatedAt > 0L) values.put("created_at_ms", existingCreatedAt);
            long id;
            if (existingId > 0L) {
                int updated = db.update(
                    "cache_entries",
                    values,
                    "id = ? AND account_key = ?",
                    new String[] { String.valueOf(existingId), entry.accountKey }
                );
                id = updated > 0 ? existingId : -1L;
            } else {
                id = db.insertWithOnConflict(
                    "cache_entries",
                    null,
                    values,
                    SQLiteDatabase.CONFLICT_ABORT
                );
            }
            if (id > 0L) entry.id = id;
            db.setTransactionSuccessful();
            return id;
        } finally {
            db.endTransaction();
        }
    }

    synchronized List<LocalCacheEntry> query(String accountKey, LocalCacheFilter filter) {
        if (empty(accountKey)) return Collections.emptyList();
        List<String> clauses = new ArrayList<>();
        List<String> args = new ArrayList<>();
        clauses.add("account_key = ?");
        args.add(accountKey);
        if (filter != null) {
            appendCategories(filter.categories, clauses, args);
            if (!empty(filter.sourceType)) {
                clauses.add("source_type = ?");
                args.add(filter.sourceType);
            }
            if (!empty(filter.sourceId)) {
                clauses.add("source_id = ?");
                args.add(filter.sourceId);
            }
            if (filter.fromTimeMs > 0L) {
                clauses.add("created_at_ms >= ?");
                args.add(String.valueOf(filter.fromTimeMs));
            }
            if (filter.toTimeMs > 0L) {
                clauses.add("created_at_ms <= ?");
                args.add(String.valueOf(filter.toTimeMs));
            }
            if (filter.protectedOnly != null) {
                clauses.add("protected = ?");
                args.add(filter.protectedOnly ? "1" : "0");
            }
            if (!empty(filter.query)) {
                clauses.add("(display_name LIKE ? OR source_title LIKE ?)");
                String query = "%" + filter.query.trim() + "%";
                args.add(query);
                args.add(query);
            }
        }
        List<LocalCacheEntry> entries = new ArrayList<>();
        try (Cursor cursor = getReadableDatabase().query(
            "cache_entries",
            null,
            TextUtils.join(" AND ", clauses),
            args.toArray(new String[0]),
            null,
            null,
            "created_at_ms DESC, id DESC"
        )) {
            while (cursor.moveToNext()) entries.add(read(cursor));
        }
        return entries;
    }

    synchronized List<Source> sources(String accountKey) {
        if (empty(accountKey)) return Collections.emptyList();
        List<Source> sources = new ArrayList<>();
        try (Cursor cursor = getReadableDatabase().rawQuery(
            "SELECT source_type, source_id, MAX(source_title), COUNT(*) FROM cache_entries "
                + "WHERE account_key = ? GROUP BY source_type, source_id "
                + "ORDER BY MAX(accessed_at_ms) DESC",
            new String[] { accountKey }
        )) {
            while (cursor.moveToNext()) {
                sources.add(new Source(
                    cursor.getString(0),
                    cursor.getString(1),
                    cursor.getString(2),
                    cursor.getInt(3)
                ));
            }
        }
        return sources;
    }

    synchronized void touch(String accountKey, String originKey, long timeMs) {
        if (empty(accountKey) || empty(originKey)) return;
        ContentValues values = new ContentValues();
        values.put("accessed_at_ms", timeMs);
        getWritableDatabase().update(
            "cache_entries",
            values,
            "account_key = ? AND origin_key = ?",
            new String[] { accountKey, originKey }
        );
    }

    synchronized void setProtected(String accountKey, Set<Long> ids, boolean value) {
        if (empty(accountKey) || ids == null || ids.isEmpty()) return;
        ContentValues values = new ContentValues();
        values.put("protected", value ? 1 : 0);
        SQLiteDatabase db = getWritableDatabase();
        db.beginTransaction();
        try {
            for (long id : ids) {
                db.update(
                    "cache_entries",
                    values,
                    "account_key = ? AND id = ?",
                    new String[] { accountKey, String.valueOf(id) }
                );
            }
            db.setTransactionSuccessful();
        } finally {
            db.endTransaction();
        }
    }

    synchronized void deleteIds(String accountKey, Set<Long> ids) {
        if (empty(accountKey) || ids == null || ids.isEmpty()) return;
        SQLiteDatabase db = getWritableDatabase();
        db.beginTransaction();
        try {
            for (long id : ids) {
                db.delete(
                    "cache_entries",
                    "account_key = ? AND id = ?",
                    new String[] { accountKey, String.valueOf(id) }
                );
            }
            db.setTransactionSuccessful();
        } finally {
            db.endTransaction();
        }
    }

    synchronized void deleteByExternalId(String accountKey, long downloadId) {
        if (empty(accountKey)) return;
        getWritableDatabase().delete(
            "cache_entries",
            "account_key = ? AND external_download_id = ?",
            new String[] { accountKey, String.valueOf(downloadId) }
        );
    }

    private static ContentValues values(LocalCacheEntry entry) {
        ContentValues values = new ContentValues();
        values.put("account_key", safe(entry.accountKey));
        values.put("source_type", safe(entry.sourceType));
        values.put("source_id", safe(entry.sourceId));
        values.put("source_title", safe(entry.sourceTitle));
        values.put("category", safe(entry.category));
        values.put("local_path", safe(entry.localPath));
        values.put("remote_url", safe(entry.remoteUrl));
        values.put("display_name", safe(entry.displayName));
        values.put("mime_type", safe(entry.mimeType));
        values.put("size_bytes", Math.max(0L, entry.sizeBytes));
        values.put("created_at_ms", entry.createdAtMs > 0L ? entry.createdAtMs : System.currentTimeMillis());
        values.put("accessed_at_ms", entry.accessedAtMs > 0L ? entry.accessedAtMs : System.currentTimeMillis());
        values.put("protected", entry.protectedFromCleanup ? 1 : 0);
        values.put("external_download_id", entry.externalDownloadId);
        values.put("origin_key", safe(entry.originKey));
        return values;
    }

    private static LocalCacheEntry read(Cursor cursor) {
        LocalCacheEntry entry = new LocalCacheEntry();
        entry.id = number(cursor, "id");
        entry.accountKey = string(cursor, "account_key");
        entry.sourceType = string(cursor, "source_type");
        entry.sourceId = string(cursor, "source_id");
        entry.sourceTitle = string(cursor, "source_title");
        entry.category = string(cursor, "category");
        entry.localPath = string(cursor, "local_path");
        entry.remoteUrl = string(cursor, "remote_url");
        entry.displayName = string(cursor, "display_name");
        entry.mimeType = string(cursor, "mime_type");
        entry.sizeBytes = number(cursor, "size_bytes");
        entry.createdAtMs = number(cursor, "created_at_ms");
        entry.accessedAtMs = number(cursor, "accessed_at_ms");
        entry.protectedFromCleanup = number(cursor, "protected") == 1L;
        entry.externalDownloadId = number(cursor, "external_download_id");
        entry.originKey = string(cursor, "origin_key");
        return entry;
    }

    private static void appendCategories(
        Set<String> categories,
        List<String> clauses,
        List<String> args
    ) {
        if (categories == null || categories.isEmpty()) return;
        List<String> placeholders = new ArrayList<>();
        for (String category : categories) {
            placeholders.add("?");
            args.add(category);
        }
        clauses.add("category IN (" + TextUtils.join(",", placeholders) + ")");
    }

    private static String string(Cursor cursor, String column) {
        return cursor.getString(cursor.getColumnIndexOrThrow(column));
    }

    private static long number(Cursor cursor, String column) {
        return cursor.getLong(cursor.getColumnIndexOrThrow(column));
    }

    private static String safe(String value) {
        return value == null ? "" : value;
    }

    private static boolean empty(String value) {
        return value == null || value.trim().isEmpty();
    }

    static final class Source {
        final String type;
        final String id;
        final String title;
        final int count;

        Source(String type, String id, String title, int count) {
            this.type = type;
            this.id = id;
            this.title = title;
            this.count = count;
        }
    }
}