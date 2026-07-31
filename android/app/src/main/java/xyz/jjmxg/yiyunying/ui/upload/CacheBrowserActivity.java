package xyz.jjmxg.yiyunying.ui.upload;

import android.content.Context;
import android.content.Intent;
import android.os.Bundle;
import android.text.Editable;
import android.text.TextWatcher;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.widget.ImageView;
import android.widget.TextView;

import androidx.annotation.NonNull;
import androidx.core.content.ContextCompat;
import androidx.recyclerview.widget.LinearLayoutManager;
import androidx.recyclerview.widget.RecyclerView;

import com.google.android.material.button.MaterialButton;
import com.google.android.material.checkbox.MaterialCheckBox;
import com.google.android.material.snackbar.Snackbar;

import java.text.SimpleDateFormat;
import java.util.ArrayList;
import java.util.Collections;
import java.util.Date;
import java.util.LinkedHashSet;
import java.util.List;
import java.util.Locale;
import java.util.Map;
import java.util.Set;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.data.cache.AutoCachePolicyStore;
import xyz.jjmxg.yiyunying.data.cache.LocalCacheEntry;
import xyz.jjmxg.yiyunying.data.cache.LocalCacheFilter;
import xyz.jjmxg.yiyunying.data.cache.LocalCacheManager;
import xyz.jjmxg.yiyunying.ui.common.YiyunyingDialogBuilder;

/** Type-aware, account-scoped browser for offline and downloaded content. */
public final class CacheBrowserActivity extends xyz.jjmxg.yiyunying.ui.common.SystemInsetActivity {
    private final Set<Long> selectedIds = new LinkedHashSet<>();
    private final LocalCacheFilter filter = new LocalCacheFilter();
    private final List<LocalCacheEntry> visibleEntries = new ArrayList<>();
    private LocalCacheManager manager;
    private CacheAdapter adapter;
    private TextView summary;
    private TextView emptyState;
    private MaterialButton categoryFilter;
    private MaterialButton sourceFilter;
    private MaterialButton timeFilter;
    private MaterialButton protectButton;
    private MaterialButton deleteButton;
    private String selectedSourceTitle = "全部来源";
    private String selectedTimeTitle = "全部时间";

    public static void open(Context context) {
        context.startActivity(new Intent(context, CacheBrowserActivity.class));
    }

    @Override protected void onCreate(Bundle state) {
        super.onCreate(state);
        setContentView(R.layout.activity_cache_browser);
        manager = LocalCacheManager.get(this);

        com.google.android.material.appbar.MaterialToolbar toolbar = findViewById(R.id.toolbar);
        toolbar.setNavigationOnClickListener(view -> finish());
        summary = findViewById(R.id.summary);
        emptyState = findViewById(R.id.emptyState);
        categoryFilter = findViewById(R.id.categoryFilter);
        sourceFilter = findViewById(R.id.sourceFilter);
        timeFilter = findViewById(R.id.timeFilter);
        protectButton = findViewById(R.id.protectButton);
        deleteButton = findViewById(R.id.deleteButton);

        RecyclerView list = findViewById(R.id.list);
        list.setLayoutManager(new LinearLayoutManager(this));
        list.setHasFixedSize(true);
        adapter = new CacheAdapter();
        list.setAdapter(adapter);

        com.google.android.material.textfield.TextInputEditText search = findViewById(R.id.searchInput);
        search.addTextChangedListener(new TextWatcher() {
            @Override public void beforeTextChanged(CharSequence value, int start, int count, int after) { }
            @Override public void onTextChanged(CharSequence value, int start, int before, int count) {
                filter.query = value == null ? "" : value.toString();
                reload();
            }
            @Override public void afterTextChanged(Editable value) { }
        });
        categoryFilter.setOnClickListener(view -> chooseCategory());
        sourceFilter.setOnClickListener(view -> chooseSource());
        timeFilter.setOnClickListener(view -> chooseTime());
        findViewById(R.id.selectAllButton).setOnClickListener(view -> toggleSelectAll());
        protectButton.setOnClickListener(view -> toggleProtection());
        deleteButton.setOnClickListener(view -> confirmDelete());
        reload();
    }

    @Override protected void onResume() {
        super.onResume();
        if (manager != null) reload();
    }

    private void reload() {
        visibleEntries.clear();
        visibleEntries.addAll(manager.list(filter));
        Set<Long> visibleIds = new LinkedHashSet<>();
        for (LocalCacheEntry entry : visibleEntries) visibleIds.add(entry.id);
        selectedIds.retainAll(visibleIds);
        adapter.notifyDataSetChanged();
        emptyState.setVisibility(visibleEntries.isEmpty() ? View.VISIBLE : View.GONE);
        renderActions();
    }

    private void renderActions() {
        long bytes = 0L;
        int protectedCount = 0;
        for (LocalCacheEntry entry : visibleEntries) {
            bytes += Math.max(0L, entry.sizeBytes);
            if (entry.protectedFromCleanup) protectedCount++;
        }
        summary.setText(visibleEntries.size() + " 项 · " + format(bytes)
            + (protectedCount > 0 ? " · " + protectedCount + " 项已保留" : "")
            + (selectedIds.isEmpty() ? "" : " · 已选 " + selectedIds.size() + " 项"));
        protectButton.setEnabled(!selectedIds.isEmpty());
        deleteButton.setEnabled(!selectedIds.isEmpty());
    }

    private void chooseCategory() {
        List<String> keys = new ArrayList<>();
        List<String> labels = new ArrayList<>();
        keys.add("");
        labels.add("全部类别");
        for (Map.Entry<String, String> entry : AutoCachePolicyStore.labels().entrySet()) {
            keys.add(entry.getKey());
            labels.add(entry.getValue());
        }
        int checked = 0;
        if (!filter.categories.isEmpty()) {
            String current = filter.categories.iterator().next();
            checked = Math.max(0, keys.indexOf(current));
        }
        new YiyunyingDialogBuilder(this)
            .setTitle("筛选缓存类别")
            .setSingleChoiceItems(labels.toArray(new String[0]), checked, (dialog, which) -> {
                filter.categories.clear();
                if (which > 0) filter.categories.add(keys.get(which));
                categoryFilter.setText(labels.get(which));
                dialog.dismiss();
                reload();
            })
            .setNegativeButton("取消", null)
            .show();
    }

    private void chooseSource() {
        List<LocalCacheManager.CacheSource> sources = manager.sources();
        String[] labels = new String[sources.size() + 1];
        labels[0] = "全部来源";
        for (int index = 0; index < sources.size(); index++) {
            LocalCacheManager.CacheSource source = sources.get(index);
            labels[index + 1] = source.title + " · " + source.count + " 项";
        }
        new YiyunyingDialogBuilder(this)
            .setTitle("按好友、群组或聊天室筛选")
            .setItems(labels, (dialog, which) -> {
                if (which == 0) {
                    filter.sourceType = "";
                    filter.sourceId = "";
                    selectedSourceTitle = "全部来源";
                } else {
                    LocalCacheManager.CacheSource source = sources.get(which - 1);
                    filter.sourceType = source.type;
                    filter.sourceId = source.id;
                    selectedSourceTitle = source.title;
                }
                sourceFilter.setText(selectedSourceTitle);
                reload();
            })
            .setNegativeButton("取消", null)
            .show();
    }

    private void chooseTime() {
        String[] labels = { "全部时间", "最近 7 天", "最近 30 天", "最近 90 天", "90 天以前" };
        new YiyunyingDialogBuilder(this)
            .setTitle("按缓存时间筛选")
            .setItems(labels, (dialog, which) -> {
                long now = System.currentTimeMillis();
                filter.fromTimeMs = 0L;
                filter.toTimeMs = 0L;
                if (which == 1) filter.fromTimeMs = now - days(7);
                if (which == 2) filter.fromTimeMs = now - days(30);
                if (which == 3) filter.fromTimeMs = now - days(90);
                if (which == 4) filter.toTimeMs = now - days(90);
                selectedTimeTitle = labels[which];
                timeFilter.setText(selectedTimeTitle);
                reload();
            })
            .setNegativeButton("取消", null)
            .show();
    }

    private void toggleSelectAll() {
        if (selectedIds.size() == visibleEntries.size()) {
            selectedIds.clear();
        } else {
            selectedIds.clear();
            for (LocalCacheEntry entry : visibleEntries) selectedIds.add(entry.id);
        }
        adapter.notifyDataSetChanged();
        renderActions();
    }

    private void toggleSelection(long id) {
        if (!selectedIds.add(id)) selectedIds.remove(id);
        adapter.notifyDataSetChanged();
        renderActions();
    }

    private void toggleProtection() {
        if (selectedIds.isEmpty()) return;
        boolean allProtected = true;
        for (LocalCacheEntry entry : visibleEntries) {
            if (selectedIds.contains(entry.id) && !entry.protectedFromCleanup) {
                allProtected = false;
                break;
            }
        }
        manager.protect(selectedIds, !allProtected);
        Snackbar.make(summary, allProtected ? "已允许自动清理" : "已标记为保留不删", Snackbar.LENGTH_LONG).show();
        selectedIds.clear();
        reload();
    }

    private void confirmDelete() {
        if (selectedIds.isEmpty()) return;
        int count = selectedIds.size();
        new YiyunyingDialogBuilder(this)
            .setTitle("删除所选缓存")
            .setMessage("将从本机删除已选的 " + count + " 项内容。云端消息和已上传内容不会被删除。")
            .setPositiveButton("删除", (dialog, which) -> {
                int removed = manager.delete(new LinkedHashSet<>(selectedIds), true);
                selectedIds.clear();
                Snackbar.make(summary, "已删除 " + removed + " 项本机缓存", Snackbar.LENGTH_LONG).show();
                reload();
            })
            .setNegativeButton("取消", null)
            .show();
    }

    private static long days(int days) {
        return days * 86_400_000L;
    }

    private static String format(long bytes) {
        if (bytes < 1024L) return bytes + " B";
        if (bytes < 1024L * 1024L) return String.format(Locale.CHINA, "%.1f KB", bytes / 1024d);
        if (bytes < 1024L * 1024L * 1024L) return String.format(Locale.CHINA, "%.1f MB", bytes / 1024d / 1024d);
        return String.format(Locale.CHINA, "%.2f GB", bytes / 1024d / 1024d / 1024d);
    }

    private final class CacheAdapter extends RecyclerView.Adapter<CacheHolder> {
        @NonNull @Override public CacheHolder onCreateViewHolder(@NonNull ViewGroup parent, int viewType) {
            View view = LayoutInflater.from(parent.getContext()).inflate(R.layout.item_local_cache, parent, false);
            return new CacheHolder(view);
        }

        @Override public void onBindViewHolder(@NonNull CacheHolder holder, int position) {
            LocalCacheEntry entry = visibleEntries.get(position);
            holder.check.setOnCheckedChangeListener(null);
            holder.check.setChecked(selectedIds.contains(entry.id));
            holder.title.setText(entry.displayName == null || entry.displayName.isEmpty() ? "缓存内容" : entry.displayName);
            String source = entry.sourceTitle == null || entry.sourceTitle.isEmpty() ? "其他来源" : entry.sourceTitle;
            String time = new SimpleDateFormat("yyyy-MM-dd HH:mm", Locale.CHINA).format(new Date(entry.createdAtMs));
            holder.metadata.setText(AutoCachePolicyStore.label(entry.category) + " · " + format(entry.sizeBytes)
                + "\n" + source + " · " + time);
            holder.icon.setImageResource(icon(entry.category));
            holder.badge.setVisibility(entry.protectedFromCleanup ? View.VISIBLE : View.GONE);
            holder.check.setOnCheckedChangeListener((button, checked) -> toggleSelection(entry.id));
            holder.itemView.setOnClickListener(view -> toggleSelection(entry.id));
        }

        @Override public int getItemCount() { return visibleEntries.size(); }
    }

    private static final class CacheHolder extends RecyclerView.ViewHolder {
        final MaterialCheckBox check;
        final ImageView icon;
        final TextView title;
        final TextView metadata;
        final TextView badge;

        CacheHolder(View view) {
            super(view);
            check = view.findViewById(R.id.selected);
            icon = view.findViewById(R.id.icon);
            title = view.findViewById(R.id.title);
            metadata = view.findViewById(R.id.metadata);
            badge = view.findViewById(R.id.protectedBadge);
        }
    }

    private static int icon(String category) {
        if (AutoCachePolicyStore.IMAGE.equals(category) || AutoCachePolicyStore.STICKER.equals(category)) return R.drawable.ic_album;
        if (AutoCachePolicyStore.VIDEO.equals(category)) return R.drawable.ic_video;
        if (AutoCachePolicyStore.VOICE.equals(category)) return R.drawable.ic_voice;
        if (AutoCachePolicyStore.AUDIO.equals(category)) return R.drawable.ic_volume;
        if (AutoCachePolicyStore.DOCUMENT.equals(category)) return R.drawable.ic_document;
        if (AutoCachePolicyStore.PROFILE.equals(category)) return R.drawable.ic_person;
        if (AutoCachePolicyStore.CHAT_RECORD.equals(category)) return R.drawable.ic_chat;
        return R.drawable.ic_file;
    }
}