package xyz.jjmxg.yiyunying.ui.upload;

import android.Manifest;
import android.content.ContentUris;
import android.content.Context;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.database.Cursor;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.Environment;
import android.provider.MediaStore;
import android.provider.OpenableColumns;
import android.provider.Settings;
import android.text.Editable;
import android.text.TextWatcher;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.webkit.MimeTypeMap;
import android.widget.TextView;

import androidx.activity.result.ActivityResultLauncher;
import androidx.activity.result.contract.ActivityResultContracts;
import androidx.annotation.NonNull;
import androidx.core.content.ContextCompat;
import androidx.recyclerview.widget.LinearLayoutManager;
import androidx.recyclerview.widget.RecyclerView;

import com.google.android.material.snackbar.Snackbar;
import com.google.gson.JsonObject;

import java.text.SimpleDateFormat;
import java.util.ArrayList;
import java.util.Date;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Locale;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.databinding.ActivityFilePickerBinding;
import xyz.jjmxg.yiyunying.databinding.ItemLocalFileBinding;
import xyz.jjmxg.yiyunying.ui.common.SystemInsetActivity;

/** In-app file browser used by chat, forum and upload surfaces. */
public final class FilePickerActivity extends SystemInsetActivity {
    public static final String EXTRA_SELECTED_URIS = "selected_file_uris";
    private static final String EXTRA_MAX_COUNT = "max_count";
    private static final String STATE_URIS = "state_uris";
    private static final int MAX_QUERY_COUNT = 5000;

    private ActivityFilePickerBinding binding;
    private final List<LocalFile> files = new ArrayList<>();
    private final List<LocalFile> visibleFiles = new ArrayList<>();
    private final List<FileRow> visibleRows = new ArrayList<>();
    private final LinkedHashMap<String, LocalFile> selectedFiles = new LinkedHashMap<>();
    private final ExecutorService loader = Executors.newSingleThreadExecutor();
    private final FileAdapter adapter = new FileAdapter();
    private int maxCount = 50;
    private int activeFilter = R.id.filterAll;
    private boolean loading;
    private boolean loaded;
    private boolean permissionKnownGranted;

    private final ActivityResultLauncher<String> legacyPermission = registerForActivityResult(
        new ActivityResultContracts.RequestPermission(), granted -> {
            permissionKnownGranted = granted;
            if (granted) loadFiles(true); else render();
        });

    private final ActivityResultLauncher<Intent> allFilesPermission = registerForActivityResult(
        new ActivityResultContracts.StartActivityForResult(), result -> {
            permissionKnownGranted = hasFilePermission();
            if (permissionKnownGranted) loadFiles(true); else render();
        });

    public static Intent pickerIntent(Context context, int maxCount) {
        return new Intent(context, FilePickerActivity.class)
            .putExtra(EXTRA_MAX_COUNT, Math.max(1, maxCount));
    }

    @Override protected void onCreate(Bundle state) {
        super.onCreate(state);
        binding = ActivityFilePickerBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());
        maxCount = Math.max(1, getIntent().getIntExtra(EXTRA_MAX_COUNT, 50));
        permissionKnownGranted = hasFilePermission();

        binding.toolbar.setNavigationOnClickListener(view -> finish());
        binding.fileList.setLayoutManager(new LinearLayoutManager(this));
        binding.fileList.setHasFixedSize(true);
        binding.fileList.setItemViewCacheSize(18);
        binding.fileList.setAdapter(adapter);
        binding.permissionButton.setOnClickListener(view -> requestFilePermission());
        binding.clearSelection.setOnClickListener(view -> clearSelection());
        binding.confirmSelection.setOnClickListener(view -> finishWithSelection());
        binding.filterGroup.setOnCheckedStateChangeListener((group, checkedIds) -> {
            activeFilter = checkedIds.isEmpty() ? R.id.filterAll : checkedIds.get(0);
            applyFilter();
        });
        binding.searchInput.addTextChangedListener(new TextWatcher() {
            @Override public void beforeTextChanged(CharSequence text, int start, int count, int after) { }
            @Override public void onTextChanged(CharSequence text, int start, int before, int count) { applyFilter(); }
            @Override public void afterTextChanged(Editable text) { }
        });

        if (state != null) {
            ArrayList<String> restored = state.getStringArrayList(STATE_URIS);
            if (restored != null) {
                for (String raw : restored) {
                    Uri uri = Uri.parse(raw);
                    selectedFiles.put(raw, readFile(uri));
                }
            }
        }
        render();
        if (permissionKnownGranted) loadFiles(false);
    }

    @Override protected void onResume() {
        super.onResume();
        if (binding == null) return;
        boolean granted = hasFilePermission();
        if (granted && (!permissionKnownGranted || !loaded)) loadFiles(true);
        permissionKnownGranted = granted;
        render();
    }

    @Override protected void onSaveInstanceState(@NonNull Bundle outState) {
        super.onSaveInstanceState(outState);
        outState.putStringArrayList(STATE_URIS, new ArrayList<>(selectedFiles.keySet()));
    }

    @Override protected void onDestroy() {
        binding = null;
        loader.shutdownNow();
        super.onDestroy();
    }

    private boolean hasFilePermission() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) return Environment.isExternalStorageManager();
        return ContextCompat.checkSelfPermission(this, Manifest.permission.READ_EXTERNAL_STORAGE)
            == PackageManager.PERMISSION_GRANTED;
    }

    private void requestFilePermission() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.R) {
            legacyPermission.launch(Manifest.permission.READ_EXTERNAL_STORAGE);
            return;
        }
        try {
            allFilesPermission.launch(new Intent(Settings.ACTION_MANAGE_APP_ALL_FILES_ACCESS_PERMISSION)
                .setData(Uri.parse("package:" + getPackageName())));
        } catch (RuntimeException exception) {
            allFilesPermission.launch(new Intent(Settings.ACTION_MANAGE_ALL_FILES_ACCESS_PERMISSION));
        }
    }

    private void loadFiles(boolean force) {
        if (loading || (!force && loaded) || !hasFilePermission()) return;
        loading = true;
        render();
        loader.execute(() -> {
            List<LocalFile> values = queryFiles();
            runOnUiThread(() -> {
                if (binding == null) return;
                loading = false;
                loaded = true;
                files.clear();
                files.addAll(values);
                for (LocalFile file : files) {
                    LocalFile selected = selectedFiles.get(file.uri.toString());
                    if (selected != null) selectedFiles.put(file.uri.toString(), file);
                }
                applyFilter();
            });
        });
    }

    private List<LocalFile> queryFiles() {
        List<LocalFile> result = new ArrayList<>();
        Uri collection = MediaStore.Files.getContentUri("external");
        List<String> columns = new ArrayList<>();
        columns.add(MediaStore.MediaColumns._ID);
        columns.add(MediaStore.MediaColumns.DISPLAY_NAME);
        columns.add(MediaStore.MediaColumns.MIME_TYPE);
        columns.add(MediaStore.MediaColumns.SIZE);
        columns.add(MediaStore.MediaColumns.DATE_MODIFIED);
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) columns.add(MediaStore.MediaColumns.RELATIVE_PATH);
        else columns.add(MediaStore.MediaColumns.DATA);
        try (Cursor cursor = getContentResolver().query(
            collection,
            columns.toArray(new String[0]),
            MediaStore.MediaColumns.SIZE + " IS NOT NULL",
            null,
            MediaStore.MediaColumns.DATE_MODIFIED + " DESC"
        )) {
            if (cursor == null) return result;
            int idColumn = cursor.getColumnIndexOrThrow(MediaStore.MediaColumns._ID);
            int nameColumn = cursor.getColumnIndexOrThrow(MediaStore.MediaColumns.DISPLAY_NAME);
            int mimeColumn = cursor.getColumnIndexOrThrow(MediaStore.MediaColumns.MIME_TYPE);
            int sizeColumn = cursor.getColumnIndexOrThrow(MediaStore.MediaColumns.SIZE);
            int dateColumn = cursor.getColumnIndexOrThrow(MediaStore.MediaColumns.DATE_MODIFIED);
            int pathColumn = cursor.getColumnIndex(Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q
                ? MediaStore.MediaColumns.RELATIVE_PATH : MediaStore.MediaColumns.DATA);
            while (cursor.moveToNext() && result.size() < MAX_QUERY_COUNT) {
                String name = cursor.isNull(nameColumn) ? "" : cursor.getString(nameColumn);
                if (name == null || name.trim().isEmpty() || name.endsWith("/")) continue;
                long size = cursor.isNull(sizeColumn) ? -1L : cursor.getLong(sizeColumn);
                if (size < 0L) continue;
                String mime = cursor.isNull(mimeColumn) ? "" : cursor.getString(mimeColumn);
                if (mime == null || mime.trim().isEmpty()) mime = mimeFromName(name);
                String path = pathColumn < 0 || cursor.isNull(pathColumn) ? "" : cursor.getString(pathColumn);
                Uri uri = ContentUris.withAppendedId(collection, cursor.getLong(idColumn));
                long modified = cursor.isNull(dateColumn) ? 0L : cursor.getLong(dateColumn) * 1000L;
                result.add(new LocalFile(uri, name, mime, size, path == null ? "" : path, modified));
            }
        } catch (RuntimeException ignored) {
            // Permission state is rendered by the page; do not expose platform exception text to users.
        }
        return result;
    }

    private String mimeFromName(String name) {
        String extension = extensionOf(name).toLowerCase(Locale.ROOT);
        String value = MimeTypeMap.getSingleton().getMimeTypeFromExtension(extension);
        return value == null ? "application/octet-stream" : value;
    }

    private void applyFilter() {
        if (binding == null) return;
        String query = binding.searchInput.getText() == null
            ? "" : binding.searchInput.getText().toString().trim().toLowerCase(Locale.ROOT);
        visibleFiles.clear();
        for (LocalFile file : files) {
            if (!query.isEmpty()
                && !file.name.toLowerCase(Locale.ROOT).contains(query)
                && !file.path.toLowerCase(Locale.ROOT).contains(query)) continue;
            if (!matchesActiveFilter(file)) continue;
            visibleFiles.add(file);
        }
        visibleRows.clear();
        String previousSection = "";
        for (LocalFile file : visibleFiles) {
            String section = dateSection(file.modifiedAt);
            if (!section.equals(previousSection)) {
                visibleRows.add(FileRow.section(section));
                previousSection = section;
            }
            visibleRows.add(FileRow.file(file));
        }
        adapter.notifyDataSetChanged();
        render();
    }

    private boolean matchesActiveFilter(LocalFile file) {
        if (activeFilter == R.id.filterAll) return true;
        String category = category(file);
        if (activeFilter == R.id.filterDocument) return "document".equals(category);
        if (activeFilter == R.id.filterArchive) return "archive".equals(category);
        if (activeFilter == R.id.filterMedia) return "media".equals(category);
        if (activeFilter == R.id.filterApp) return "app".equals(category);
        return true;
    }

    private String category(LocalFile file) {
        String mime = file.mime.toLowerCase(Locale.ROOT);
        String extension = extensionOf(file.name).toLowerCase(Locale.ROOT);
        if ("apk".equals(extension) || mime.contains("android.package")) return "app";
        if (mime.startsWith("image/") || mime.startsWith("video/") || mime.startsWith("audio/")) return "media";
        if ("zip".equals(extension) || "7z".equals(extension) || "rar".equals(extension)
            || "tar".equals(extension) || "gz".equals(extension) || "bz2".equals(extension)
            || "xz".equals(extension)) return "archive";
        return "document";
    }

    private void toggle(LocalFile file) {
        String key = file.uri.toString();
        if (selectedFiles.containsKey(key)) selectedFiles.remove(key);
        else if (selectedFiles.size() >= maxCount) {
            Snackbar.make(binding.getRoot(), "最多选择 " + maxCount + " 个文件", Snackbar.LENGTH_SHORT).show();
            return;
        } else selectedFiles.put(key, file);
        adapter.notifyDataSetChanged();
        render();
    }

    private void clearSelection() {
        if (selectedFiles.isEmpty()) return;
        selectedFiles.clear();
        adapter.notifyDataSetChanged();
        render();
    }

    private LocalFile readFile(Uri uri) {
        String name = "本地文件";
        long size = -1L;
        try (Cursor cursor = getContentResolver().query(
            uri, new String[]{OpenableColumns.DISPLAY_NAME, OpenableColumns.SIZE}, null, null, null)) {
            if (cursor != null && cursor.moveToFirst()) {
                int nameColumn = cursor.getColumnIndex(OpenableColumns.DISPLAY_NAME);
                int sizeColumn = cursor.getColumnIndex(OpenableColumns.SIZE);
                if (nameColumn >= 0 && !cursor.isNull(nameColumn)) name = cursor.getString(nameColumn);
                if (sizeColumn >= 0 && !cursor.isNull(sizeColumn)) size = cursor.getLong(sizeColumn);
            }
        } catch (RuntimeException ignored) { }
        String mime = getContentResolver().getType(uri);
        if (mime == null || mime.trim().isEmpty()) mime = mimeFromName(name);
        return new LocalFile(uri, name, mime, size, "", 0L);
    }

    private void preview(LocalFile file) {
        JsonObject value = new JsonObject();
        value.addProperty("original_name", file.name);
        value.addProperty("file_url", file.uri.toString());
        value.addProperty("mime_type", file.mime);
        value.addProperty("size_bytes", file.size);
        FilePreviewActivity.open(this, value);
    }

    private void render() {
        if (binding == null) return;
        boolean granted = hasFilePermission();
        binding.permissionPanel.setVisibility(granted ? View.GONE : View.VISIBLE);
        binding.progress.setVisibility(granted && loading ? View.VISIBLE : View.GONE);
        boolean empty = granted && loaded && !loading && visibleFiles.isEmpty();
        binding.emptyState.setVisibility(empty ? View.VISIBLE : View.GONE);
        binding.fileList.setVisibility(granted && !loading && !visibleFiles.isEmpty() ? View.VISIBLE : View.GONE);
        int count = selectedFiles.size();
        binding.selectedCount.setText("已选 " + count + " / " + maxCount + " 个");
        binding.clearSelection.setEnabled(count > 0);
        binding.confirmSelection.setEnabled(count > 0);
    }

    private void finishWithSelection() {
        ArrayList<String> uris = new ArrayList<>(selectedFiles.keySet());
        setResult(RESULT_OK, new Intent().putStringArrayListExtra(EXTRA_SELECTED_URIS, uris));
        finish();
    }

    private String metadata(LocalFile file) {
        String extension = extensionOf(file.name);
        String type = friendlyFileType(file, extension);
        StringBuilder text = new StringBuilder(type).append("  ·  ").append(formatSize(file.size));
        if (file.modifiedAt > 0L) text.append("  ·  ").append(formatModified(file.modifiedAt));
        if (!file.path.trim().isEmpty()) text.append("\n").append(file.path);
        return text.toString();
    }

    private String friendlyFileType(LocalFile file, String extension) {
        String value = extension == null ? "" : extension.toLowerCase(Locale.ROOT);
        if ("apk".equals(value)) return "安卓安装包";
        if ("hap".equals(value)) return "鸿蒙安装包";
        if ("ipa".equals(value)) return "苹果安装包";
        if ("pdf".equals(value)) return "PDF 文档";
        if ("doc".equals(value) || "docx".equals(value)) return "Word 文档";
        if ("xls".equals(value) || "xlsx".equals(value)) return "Excel 表格";
        if ("ppt".equals(value) || "pptx".equals(value)) return "演示文稿";
        if ("zip".equals(value) || "7z".equals(value) || "rar".equals(value)
            || "tar".equals(value) || "gz".equals(value)) return value.toUpperCase(Locale.ROOT) + " 压缩包";
        if (file.mime.startsWith("image/")) return "图片" + suffix(value);
        if (file.mime.startsWith("video/")) return "视频" + suffix(value);
        if (file.mime.startsWith("audio/")) return "音频" + suffix(value);
        return value.isEmpty() ? friendlyType(file.mime) : value.toUpperCase(Locale.ROOT) + " 文件";
    }

    private String suffix(String extension) {
        return extension == null || extension.isEmpty() ? "" : "（" + extension.toUpperCase(Locale.ROOT) + "）";
    }

    private String formatModified(long time) {
        long age = Math.max(0L, System.currentTimeMillis() - time);
        if (age < 24L * 60L * 60L * 1000L) {
            return "今天 " + new SimpleDateFormat("HH:mm", Locale.CHINA).format(new Date(time));
        }
        return new SimpleDateFormat("yyyy年M月d日", Locale.CHINA).format(new Date(time));
    }

    private String dateSection(long time) {
        if (time <= 0L) return "时间未知";
        long age = Math.max(0L, System.currentTimeMillis() - time);
        long day = 24L * 60L * 60L * 1000L;
        if (age < day) return "今天";
        if (age < 7L * day) return "7 天内";
        if (age < 30L * day) return "30 天内";
        return "更早";
    }

    private String friendlyType(String mime) {
        if (mime.startsWith("image/")) return "图片";
        if (mime.startsWith("video/")) return "视频";
        if (mime.startsWith("audio/")) return "音频";
        return "文件";
    }

    private String extensionOf(String name) {
        if (name == null) return "";
        int dot = name.lastIndexOf('.');
        return dot < 0 || dot == name.length() - 1 ? "" : name.substring(dot + 1);
    }

    private String formatSize(long bytes) {
        if (bytes < 0) return "大小未知";
        if (bytes < 1024L) return bytes + " B";
        double kb = bytes / 1024d;
        if (kb < 1024d) return String.format(Locale.CHINA, "%.1f KB", kb);
        double mb = kb / 1024d;
        if (mb < 1024d) return String.format(Locale.CHINA, "%.1f MB", mb);
        return String.format(Locale.CHINA, "%.2f GB", mb / 1024d);
    }

    private int iconFor(LocalFile file) {
        String category = category(file);
        if ("media".equals(category) && file.mime.startsWith("video/")) return R.drawable.ic_video;
        if ("document".equals(category)) return R.drawable.ic_document;
        if ("archive".equals(category)) return R.drawable.ic_folder;
        return R.drawable.ic_file;
    }

    private final class FileAdapter extends RecyclerView.Adapter<RecyclerView.ViewHolder> {
        private static final int TYPE_SECTION = 0;
        private static final int TYPE_FILE = 1;

        @Override public int getItemViewType(int position) {
            return visibleRows.get(position).file == null ? TYPE_SECTION : TYPE_FILE;
        }

        @NonNull @Override public RecyclerView.ViewHolder onCreateViewHolder(@NonNull ViewGroup parent, int viewType) {
            if (viewType == TYPE_SECTION) {
                TextView title = (TextView) LayoutInflater.from(parent.getContext())
                    .inflate(R.layout.item_file_section, parent, false);
                return new SectionViewHolder(title);
            }
            ItemLocalFileBinding item = ItemLocalFileBinding.inflate(
                LayoutInflater.from(parent.getContext()), parent, false);
            return new FileViewHolder(item);
        }

        @Override public void onBindViewHolder(@NonNull RecyclerView.ViewHolder rawHolder, int position) {
            FileRow row = visibleRows.get(position);
            if (rawHolder instanceof SectionViewHolder) {
                ((SectionViewHolder) rawHolder).title.setText(row.section);
                return;
            }
            FileViewHolder holder = (FileViewHolder) rawHolder;
            LocalFile file = row.file;
            holder.binding.fileName.setText(file.name);
            holder.binding.fileMeta.setText(metadata(file));
            holder.binding.fileIcon.setImageResource(iconFor(file));
            holder.binding.selection.setOnCheckedChangeListener(null);
            holder.binding.selection.setChecked(selectedFiles.containsKey(file.uri.toString()));
            holder.binding.selection.setOnCheckedChangeListener((button, checked) -> toggle(file));
            holder.binding.previewButton.setOnClickListener(view -> preview(file));
            holder.binding.getRoot().setOnClickListener(view -> toggle(file));
            holder.binding.getRoot().setOnLongClickListener(view -> {
                preview(file);
                return true;
            });
        }

        @Override public int getItemCount() { return visibleRows.size(); }
    }

    private static final class SectionViewHolder extends RecyclerView.ViewHolder {
        final TextView title;
        SectionViewHolder(TextView title) {
            super(title);
            this.title = title;
        }
    }

    private static final class FileViewHolder extends RecyclerView.ViewHolder {
        final ItemLocalFileBinding binding;
        FileViewHolder(ItemLocalFileBinding binding) {
            super(binding.getRoot());
            this.binding = binding;
        }
    }

    private static final class LocalFile {
        final Uri uri;
        final String name;
        final String mime;
        final long size;
        final String path;
        final long modifiedAt;

        LocalFile(Uri uri, String name, String mime, long size, String path, long modifiedAt) {
            this.uri = uri;
            this.name = name == null ? "本地文件" : name;
            this.mime = mime == null ? "application/octet-stream" : mime;
            this.size = size;
            this.path = path == null ? "" : path;
            this.modifiedAt = modifiedAt;
        }
    }

    private static final class FileRow {
        final String section;
        final LocalFile file;

        private FileRow(String section, LocalFile file) {
            this.section = section == null ? "" : section;
            this.file = file;
        }

        static FileRow section(String title) { return new FileRow(title, null); }
        static FileRow file(LocalFile file) { return new FileRow("", file); }
    }
}
