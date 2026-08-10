package xyz.jjmxg.yiyunying.ui.upload;

import android.content.Context;
import android.content.Intent;
import android.content.res.ColorStateList;
import android.os.Bundle;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.widget.LinearLayout;
import android.widget.TextView;

import androidx.annotation.NonNull;
import androidx.recyclerview.widget.LinearLayoutManager;
import androidx.recyclerview.widget.RecyclerView;

import com.google.android.material.appbar.MaterialToolbar;
import com.google.android.material.button.MaterialButton;
import com.google.android.material.snackbar.Snackbar;

import java.text.SimpleDateFormat;
import java.util.ArrayList;
import java.util.Date;
import java.util.List;
import java.util.Locale;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.data.update.UpdatePackageStore;
import xyz.jjmxg.yiyunying.databinding.ItemRecordBinding;
import xyz.jjmxg.yiyunying.ui.common.AppUpdateInstaller;
import xyz.jjmxg.yiyunying.ui.common.SystemInsetActivity;
import xyz.jjmxg.yiyunying.ui.common.YiyunyingDialogBuilder;

/** Lists persistent self-update packages separately from DownloadManager-backed user downloads. */
public final class UpdatePackageHistoryActivity extends SystemInsetActivity {
    private static final String EXTRA_INSTALL_RECORD_ID = "update_package.install_record_id";

    private final List<UpdatePackageStore.Entry> items = new ArrayList<>();
    private PackageAdapter adapter;
    private TextView summaryView;
    private TextView emptyView;
    private MaterialButton clearAllButton;

    public static void open(Context context) {
        context.startActivity(intent(context, ""));
    }

    /** Creates an intent that opens the history surface and immediately installs a ready record. */
    public static Intent intent(Context context, String recordId) {
        Intent intent = new Intent(context, UpdatePackageHistoryActivity.class);
        if (recordId != null && !recordId.trim().isEmpty()) {
            intent.putExtra(EXTRA_INSTALL_RECORD_ID, recordId.trim());
        }
        return intent;
    }

    @Override protected void onCreate(Bundle state) {
        super.onCreate(state);
        setContentView(buildContent());
        if (state == null) {
            String recordId = getIntent().getStringExtra(EXTRA_INSTALL_RECORD_ID);
            if (recordId != null && !recordId.trim().isEmpty()) {
                getWindow().getDecorView().post(() -> installFromNotification(recordId.trim()));
            }
        }
    }

    @Override protected void onStart() {
        super.onStart();
        UpdatePackageStore.reconcileInstalled(this);
        refresh();
    }

    private View buildContent() {
        LinearLayout root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setBackgroundColor(getColor(R.color.surface));

        MaterialToolbar toolbar = new MaterialToolbar(this);
        toolbar.setTitle("历史安装包");
        toolbar.setNavigationIcon(R.drawable.ic_back);
        toolbar.setNavigationOnClickListener(view -> finish());
        root.addView(toolbar, new LinearLayout.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT, dp(56)));

        summaryView = new TextView(this);
        summaryView.setId(android.R.id.text1);
        summaryView.setPadding(dp(20), dp(14), dp(20), dp(8));
        summaryView.setTextAppearance(com.google.android.material.R.style.TextAppearance_Material3_BodyMedium);
        summaryView.setTextColor(getColor(R.color.on_surface_variant));
        root.addView(summaryView, new LinearLayout.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));

        clearAllButton = new MaterialButton(this, null,
            com.google.android.material.R.attr.materialButtonOutlinedStyle);
        clearAllButton.setId(android.R.id.button1);
        clearAllButton.setText("批量删除全部安装包");
        clearAllButton.setContentDescription("批量删除全部历史安装包和未完成下载");
        clearAllButton.setIconResource(R.drawable.ic_delete);
        clearAllButton.setTextColor(getColor(R.color.error));
        clearAllButton.setIconTint(ColorStateList.valueOf(getColor(R.color.error)));
        clearAllButton.setOnClickListener(view -> confirmDeleteAll());
        LinearLayout.LayoutParams clearParams = new LinearLayout.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT, dp(52));
        clearParams.leftMargin = dp(16);
        clearParams.rightMargin = dp(16);
        clearParams.bottomMargin = dp(8);
        root.addView(clearAllButton, clearParams);

        emptyView = new TextView(this);
        emptyView.setId(android.R.id.empty);
        emptyView.setGravity(android.view.Gravity.CENTER);
        emptyView.setText("暂无历史安装包\n下载中断后可从这里继续，已下载的安装包也可手动删除。");
        emptyView.setTextColor(getColor(R.color.on_surface_variant));
        emptyView.setPadding(dp(24), dp(32), dp(24), dp(32));
        root.addView(emptyView, new LinearLayout.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));

        RecyclerView recycler = new RecyclerView(this);
        recycler.setId(android.R.id.list);
        recycler.setLayoutManager(new LinearLayoutManager(this));
        recycler.setPadding(dp(12), dp(4), dp(12), dp(20));
        recycler.setClipToPadding(false);
        adapter = new PackageAdapter();
        recycler.setAdapter(adapter);
        root.addView(recycler, new LinearLayout.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT, 0, 1f));
        return root;
    }

    private void refresh() {
        if (adapter == null) return;
        items.clear();
        items.addAll(UpdatePackageStore.list(this));
        adapter.notifyDataSetChanged();
        UpdatePackageStore.Summary summary = UpdatePackageStore.summary(this);
        summaryView.setText("本机安装包 " + summary.packageCount + " 个 · "
            + readableBytes(summary.totalBytes)
            + (summary.partialCount > 0 ? " · " + summary.partialCount + " 个可继续下载" : "")
            + (summary.readyCount > 0 ? " · " + summary.readyCount + " 个可安装" : ""));
        emptyView.setVisibility(items.isEmpty() ? View.VISIBLE : View.GONE);
        clearAllButton.setEnabled(!items.isEmpty());
    }

    private void installFromNotification(String id) {
        UpdatePackageStore.Entry entry = UpdatePackageStore.find(this, id);
        if (entry == null || !entry.hasApk) {
            Snackbar.make(findViewById(android.R.id.content),
                "安装包不存在或尚未下载完成", Snackbar.LENGTH_LONG).show();
            refresh();
            return;
        }
        install(entry);
    }

    private void actions(UpdatePackageStore.Entry entry) {
        List<String> labels = new ArrayList<>();
        List<String> actions = new ArrayList<>();
        if (entry.hasApk) {
            labels.add("安装或重新安装");
            actions.add("install");
        } else if (!UpdatePackageStore.STATE_INSTALLED_AUTO_DELETED.equals(entry.state)) {
            labels.add(entry.hasPart ? "继续上次下载" : "重新下载");
            actions.add("resume");
        }
        labels.add(entry.hasApk || entry.hasPart ? "从本机删除" : "删除历史记录");
        actions.add("delete");
        new YiyunyingDialogBuilder(this)
            .setBusinessTitle(title(entry))
            .setItems(labels.toArray(new String[0]), (dialog, which) -> {
                String action = actions.get(which);
                if ("install".equals(action)) install(entry);
                else if ("resume".equals(action)) resume(entry);
                else confirmDelete(entry);
            })
            .setNegativeButton("取消", null)
            .show();
    }

    private void resume(UpdatePackageStore.Entry entry) {
        try {
            AppUpdateInstaller.resumeStoredDownload(this, entry.id);
            Snackbar.make(findViewById(android.R.id.content),
                entry.hasPart ? "正在从上次进度继续下载" : "已开始下载安装包",
                Snackbar.LENGTH_SHORT).show();
            refresh();
        } catch (RuntimeException | LinkageError exception) {
            Snackbar.make(findViewById(android.R.id.content),
                "无法继续下载，请稍后重试", Snackbar.LENGTH_LONG).show();
        }
    }

    private void install(UpdatePackageStore.Entry entry) {
        try {
            AppUpdateInstaller.installStoredPackage(this, entry.id);
        } catch (RuntimeException | LinkageError exception) {
            Snackbar.make(findViewById(android.R.id.content),
                "无法打开系统安装器，请稍后重试", Snackbar.LENGTH_LONG).show();
        }
    }

    private void confirmDelete(UpdatePackageStore.Entry entry) {
        new YiyunyingDialogBuilder(this)
            .setTitle(entry.hasApk || entry.hasPart ? "删除本机安装包" : "删除历史记录")
            .setMessage("只会删除本机保存的安装文件和下载进度，不会卸载当前软件。")
            .setPositiveButton("删除", (dialog, which) -> delete(entry))
            .setNegativeButton("取消", null)
            .show();
    }

    private void delete(UpdatePackageStore.Entry entry) {
        if (AppUpdateInstaller.cancelDownload(entry.id)) {
            Snackbar.make(findViewById(android.R.id.content),
                "下载已暂停，请再次删除", Snackbar.LENGTH_LONG).show();
            refresh();
            return;
        }
        boolean deleted = UpdatePackageStore.delete(this, entry.id);
        Snackbar.make(findViewById(android.R.id.content),
            deleted ? "已删除本机安装包" : "安装包正在使用或无法删除，请稍后重试",
            Snackbar.LENGTH_LONG).show();
        refresh();
    }

    private void confirmDeleteAll() {
        if (items.isEmpty()) return;
        new YiyunyingDialogBuilder(this)
            .setTitle("批量删除全部安装包")
            .setMessage("将删除全部历史安装包、未完成下载及其本机记录，不会卸载当前软件。")
            .setPositiveButton("全部删除", (dialog, which) -> deleteAll())
            .setNegativeButton("取消", null)
            .show();
    }

    private void deleteAll() {
        boolean paused = false;
        for (UpdatePackageStore.Entry entry : new ArrayList<>(items)) {
            if (AppUpdateInstaller.cancelDownload(entry.id)) paused = true;
        }
        if (paused) {
            Snackbar.make(findViewById(android.R.id.content),
                "已暂停正在下载的任务，请再次批量删除", Snackbar.LENGTH_LONG).show();
            refresh();
            return;
        }
        int deleted = UpdatePackageStore.deleteAll(this);
        Snackbar.make(findViewById(android.R.id.content),
            "已删除 " + deleted + " 条安装包记录", Snackbar.LENGTH_LONG).show();
        refresh();
    }

    private String title(UpdatePackageStore.Entry entry) {
        String version = entry.versionName == null || entry.versionName.trim().isEmpty()
            ? String.valueOf(entry.versionCode) : entry.versionName.trim();
        return "易运盈 " + version;
    }

    private String status(UpdatePackageStore.Entry entry) {
        if (UpdatePackageStore.STATE_PARTIAL.equals(entry.state) || entry.hasPart && !entry.hasApk) {
            long percent = entry.expectedSize <= 0L ? 0L
                : Math.min(100L, entry.bytes * 100L / entry.expectedSize);
            return "未完成 · " + percent + "% · " + readableBytes(entry.bytes)
                + " / " + readableBytes(entry.expectedSize);
        }
        if (UpdatePackageStore.STATE_PERMISSION_PENDING.equals(entry.state)) return "等待安装权限";
        if (UpdatePackageStore.STATE_INSTALL_REQUESTED.equals(entry.state)) return "等待系统安装结果";
        if (UpdatePackageStore.STATE_INSTALLED.equals(entry.state)) return "已安装 · 安装包已保留";
        if (UpdatePackageStore.STATE_INSTALLED_AUTO_DELETED.equals(entry.state)) return "已安装 · 安装包已自动删除";
        if (UpdatePackageStore.STATE_CLEANUP_PENDING.equals(entry.state)) return "已安装 · 等待清理安装包";
        if (entry.hasApk) return "已下载 · 可安装 · " + readableBytes(entry.bytes);
        return "等待下载";
    }

    private String time(UpdatePackageStore.Entry entry) {
        long value = entry.installRequestedAt > 0L ? entry.installRequestedAt
            : (entry.completedAt > 0L ? entry.completedAt : entry.createdAt);
        if (value <= 0L) return "版本代码 " + entry.versionCode;
        return "版本代码 " + entry.versionCode + " · "
            + new SimpleDateFormat("yyyy-MM-dd HH:mm", Locale.CHINA).format(new Date(value));
    }

    private static String readableBytes(long bytes) {
        if (bytes < 1024L) return bytes + " B";
        if (bytes < 1024L * 1024L) return String.format(Locale.CHINA, "%.1f KB", bytes / 1024d);
        if (bytes < 1024L * 1024L * 1024L) {
            return String.format(Locale.CHINA, "%.1f MB", bytes / 1024d / 1024d);
        }
        return String.format(Locale.CHINA, "%.2f GB", bytes / 1024d / 1024d / 1024d);
    }

    private int dp(int value) {
        return Math.round(value * getResources().getDisplayMetrics().density);
    }

    private final class PackageAdapter extends RecyclerView.Adapter<PackageAdapter.Holder> {
        @NonNull @Override public Holder onCreateViewHolder(@NonNull ViewGroup parent, int viewType) {
            return new Holder(ItemRecordBinding.inflate(
                LayoutInflater.from(parent.getContext()), parent, false));
        }

        @Override public void onBindViewHolder(@NonNull Holder holder, int position) {
            UpdatePackageStore.Entry entry = items.get(position);
            holder.binding.avatar.setText("APK");
            holder.binding.title.setText(title(entry));
            holder.binding.subtitle.setText(status(entry));
            holder.binding.metadata.setText(time(entry));
            holder.binding.moreButton.setOnClickListener(view -> actions(entry));
            holder.binding.getRoot().setOnClickListener(view -> {
                if (entry.hasApk) install(entry); else actions(entry);
            });
        }

        @Override public int getItemCount() {
            return items.size();
        }

        final class Holder extends RecyclerView.ViewHolder {
            final ItemRecordBinding binding;

            Holder(ItemRecordBinding binding) {
                super(binding.getRoot());
                this.binding = binding;
            }
        }
    }
}
