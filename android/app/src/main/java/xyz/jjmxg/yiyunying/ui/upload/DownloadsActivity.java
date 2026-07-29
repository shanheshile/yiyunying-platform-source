package xyz.jjmxg.yiyunying.ui.upload;

import android.app.DownloadManager;
import android.content.Context;
import android.content.Intent;
import android.database.Cursor;
import android.net.Uri;
import android.os.Bundle;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.widget.LinearLayout;

import androidx.annotation.NonNull;
import androidx.recyclerview.widget.LinearLayoutManager;
import androidx.recyclerview.widget.RecyclerView;

import com.google.android.material.appbar.MaterialToolbar;
import xyz.jjmxg.yiyunying.ui.common.YiyunyingDialogBuilder;
import com.google.android.material.snackbar.Snackbar;
import com.google.gson.JsonObject;

import java.text.SimpleDateFormat;
import java.util.ArrayList;
import java.util.Date;
import java.util.List;
import java.util.Locale;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.databinding.ItemRecordBinding;

public final class DownloadsActivity extends xyz.jjmxg.yiyunying.ui.common.SystemInsetActivity {
    private final List<JsonObject> items = new ArrayList<>();
    private DownloadAdapter adapter;

    public static void open(Context context) { context.startActivity(new Intent(context, DownloadsActivity.class)); }

    @Override protected void onCreate(Bundle state) {
        super.onCreate(state);
        LinearLayout root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setBackgroundColor(getColor(R.color.surface));
        MaterialToolbar toolbar = new MaterialToolbar(this);
        toolbar.setTitle("本机下载");
        toolbar.setNavigationIcon(R.drawable.ic_back);
        toolbar.setNavigationOnClickListener(view -> finish());
        root.addView(toolbar, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, dp(56)));
        RecyclerView recycler = new RecyclerView(this);
        recycler.setLayoutManager(new LinearLayoutManager(this));
        adapter = new DownloadAdapter();
        recycler.setAdapter(adapter);
        recycler.setPadding(dp(12), dp(8), dp(12), dp(20));
        root.addView(recycler, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, 0, 1f));
        setContentView(root);
    }

    @Override protected void onStart() { super.onStart(); items.clear(); items.addAll(DownloadHistoryStore.list(this)); adapter.notifyDataSetChanged(); }

    private void actions(JsonObject item) {
        String[] actions = {"打开文件", "从本机删除"};
        new YiyunyingDialogBuilder(this).setBusinessTitle(Jsons.string(item, "name"))
            .setItems(actions, (dialog, which) -> { if (which == 0) open(item); else confirmDelete(item); })
            .setNegativeButton("取消", null).show();
    }

    private void open(JsonObject item) {
        DownloadManager manager = (DownloadManager) getSystemService(DOWNLOAD_SERVICE);
        Uri uri = manager == null ? null : manager.getUriForDownloadedFile(Jsons.longValue(item, "download_id"));
        if (uri == null) { Snackbar.make(findViewById(android.R.id.content), "文件尚未下载完成或已被移除", Snackbar.LENGTH_LONG).show(); return; }
        try { startActivity(new Intent(Intent.ACTION_VIEW).setData(uri).addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)); }
        catch (RuntimeException exception) { Snackbar.make(findViewById(android.R.id.content), "没有可打开该文件的应用", Snackbar.LENGTH_LONG).show(); }
    }

    private void confirmDelete(JsonObject item) {
        new YiyunyingDialogBuilder(this).setTitle("删除本机文件")
            .setMessage("只删除当前设备上的下载文件与记录，不影响云端原文件。")
            .setPositiveButton("删除", (dialog, which) -> {
                long id = Jsons.longValue(item, "download_id");
                DownloadManager manager = (DownloadManager) getSystemService(DOWNLOAD_SERVICE);
                if (manager != null) manager.remove(id);
                DownloadHistoryStore.remove(this, id);
                items.remove(item); adapter.notifyDataSetChanged();
            }).setNegativeButton("取消", null).show();
    }

    private String status(JsonObject item) {
        DownloadManager manager = (DownloadManager) getSystemService(DOWNLOAD_SERVICE);
        if (manager == null) return "状态未知";
        try (Cursor cursor = manager.query(new DownloadManager.Query().setFilterById(Jsons.longValue(item, "download_id")))) {
            if (cursor != null && cursor.moveToFirst()) {
                int value = cursor.getInt(cursor.getColumnIndexOrThrow(DownloadManager.COLUMN_STATUS));
                if (value == DownloadManager.STATUS_SUCCESSFUL) return "已下载";
                if (value == DownloadManager.STATUS_RUNNING) return "下载中";
                if (value == DownloadManager.STATUS_PAUSED) return "已暂停";
                if (value == DownloadManager.STATUS_FAILED) return "下载失败";
            }
        } catch (RuntimeException ignored) { }
        return "等待下载";
    }

    private int dp(int value) { return Math.round(value * getResources().getDisplayMetrics().density); }

    private final class DownloadAdapter extends RecyclerView.Adapter<DownloadAdapter.Holder> {
        @NonNull @Override public Holder onCreateViewHolder(@NonNull ViewGroup parent, int viewType) {
            return new Holder(ItemRecordBinding.inflate(LayoutInflater.from(parent.getContext()), parent, false));
        }
        @Override public void onBindViewHolder(@NonNull Holder holder, int position) {
            JsonObject item = items.get(position);
            holder.binding.avatar.setText(Jsons.string(item, "category").startsWith("视") ? "视" : "文");
            holder.binding.title.setText(Jsons.string(item, "name"));
            holder.binding.subtitle.setText(Jsons.string(item, "category") + " · " + status(item));
            long time = Jsons.longValue(item, "created_at_ms");
            holder.binding.metadata.setText(time > 0 ? new SimpleDateFormat("yyyy-MM-dd HH:mm", Locale.CHINA).format(new Date(time)) : "");
            holder.binding.moreButton.setOnClickListener(view -> actions(item));
            holder.binding.getRoot().setOnClickListener(view -> open(item));
        }
        @Override public int getItemCount() { return items.size(); }
        final class Holder extends RecyclerView.ViewHolder { final ItemRecordBinding binding; Holder(ItemRecordBinding value) { super(value.getRoot()); binding = value; } }
    }
}
