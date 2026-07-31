package xyz.jjmxg.yiyunying.ui.upload;

import android.app.ActivityManager;
import android.app.DownloadManager;
import android.content.Context;
import android.content.Intent;
import android.os.Bundle;
import android.view.View;

import com.google.android.material.snackbar.Snackbar;

import java.io.File;
import java.util.ArrayList;
import java.util.Collections;
import java.util.LinkedHashSet;
import java.util.List;
import java.util.Locale;
import java.util.Map;
import java.util.Set;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

import xyz.jjmxg.yiyunying.core.AppAccess;
import xyz.jjmxg.yiyunying.data.api.RequestHandle;
import xyz.jjmxg.yiyunying.data.cache.AutoCachePolicyStore;
import xyz.jjmxg.yiyunying.data.cache.LocalCacheManager;
import xyz.jjmxg.yiyunying.databinding.ActivityCacheManagementBinding;
import xyz.jjmxg.yiyunying.ui.common.ImageLoader;
import xyz.jjmxg.yiyunying.ui.common.YiyunyingDialogBuilder;

public final class CacheManagementActivity extends xyz.jjmxg.yiyunying.ui.common.SystemInsetActivity {
    private static final String[] NETWORK_VALUES = {
        AutoCachePolicyStore.NETWORK_WIFI,
        AutoCachePolicyStore.NETWORK_ANY,
        AutoCachePolicyStore.NETWORK_NEVER
    };

    private ActivityCacheManagementBinding binding;
    private final ExecutorService executor = Executors.newSingleThreadExecutor();
    private LocalCacheManager cacheManager;
    private AutoCachePolicyStore policy;
    private RequestHandle policyRequest;
    private volatile boolean destroyed;
    private boolean bindingSettings;

    public static void open(Context context) {
        context.startActivity(new Intent(context, CacheManagementActivity.class));
    }

    @Override protected void onCreate(Bundle state) {
        super.onCreate(state);
        binding = ActivityCacheManagementBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());
        cacheManager = LocalCacheManager.get(this);
        policy = cacheManager.policy();

        binding.toolbar.setNavigationOnClickListener(view -> finish());
        binding.autoCacheSwitch.setOnCheckedChangeListener((button, checked) -> {
            if (bindingSettings) return;
            policy.setUserEnabled(checked);
            renderPolicy();
        });
        binding.cacheNetworkButton.setOnClickListener(view -> chooseNetwork(false));
        binding.cacheCategoriesButton.setOnClickListener(view -> chooseCategories());
        binding.cacheLimitButton.setOnClickListener(view -> chooseLimitKind());
        binding.cacheDetailsButton.setOnClickListener(view -> CacheBrowserActivity.open(this));
        binding.videoAutoplaySwitch.setOnCheckedChangeListener((button, checked) -> {
            if (bindingSettings) return;
            policy.setVideoAutoplayEnabled(checked);
            renderPolicy();
        });
        binding.videoPlaybackNetworkButton.setOnClickListener(view -> chooseNetwork(true));
        binding.clearCacheButton.setOnClickListener(view -> clearCache());
        binding.downloadsButton.setOnClickListener(view -> DownloadsActivity.open(this));
        binding.clearDataButton.setOnClickListener(view -> confirmClearData());

        renderPolicy();
        calculateSize();
        loadRemotePolicy();
    }

    private void loadRemotePolicy() {
        policyRequest = AppAccess.from(this).repository().get(
            "/api/user/cloud-sync/policy",
            Collections.emptyMap(),
            result -> {
                if (binding == null || destroyed || !result.isSuccessful()) return;
                policy.applyRemote(result.dataObject());
                renderPolicy();
            }
        );
    }

    private void renderPolicy() {
        if (binding == null) return;
        bindingSettings = true;
        binding.autoCacheSwitch.setChecked(policy.userEnabled());
        binding.autoCacheSwitch.setEnabled(policy.administratorAllowsCaching());
        binding.videoAutoplaySwitch.setChecked(policy.videoAutoplayEnabled());
        bindingSettings = false;

        String cacheNetwork = AutoCachePolicyStore.networkLabel(policy.cacheNetworkPolicy());
        String playNetwork = AutoCachePolicyStore.networkLabel(policy.videoAutoplayNetworkPolicy());
        binding.cacheNetworkButton.setText("缓存网络 · " + cacheNetwork);
        binding.videoPlaybackNetworkButton.setText("自动播放网络 · " + playNetwork);
        binding.videoPlaybackNetworkButton.setEnabled(policy.videoAutoplayEnabled());
        binding.cacheCategoriesButton.setText("缓存内容类别 · " + policy.selectedCategories().size() + " 项");
        binding.cacheLimitButton.setText("容量 " + format(policy.maxBytes()) + " · 保留 "
            + retentionLabel(policy.retentionDays()));
        binding.cachePolicySummary.setText(
            (policy.effectiveEnabled() ? "自动缓存已开启" : "自动缓存已关闭")
                + " · " + cacheNetwork
                + "\n已选 " + policy.selectedCategories().size() + " 类内容，最多 "
                + format(policy.maxBytes()) + "，保留 " + retentionLabel(policy.retentionDays())
                + "\n管理策略版本：" + policy.policyVersion()
        );
    }

    private void chooseNetwork(boolean autoplay) {
        String current = autoplay ? policy.videoAutoplayNetworkPolicy() : policy.cacheNetworkPolicy();
        String[] labels = new String[NETWORK_VALUES.length];
        int checked = 0;
        for (int index = 0; index < NETWORK_VALUES.length; index++) {
            labels[index] = AutoCachePolicyStore.networkLabel(NETWORK_VALUES[index]);
            if (NETWORK_VALUES[index].equals(current)) checked = index;
        }
        final int[] selected = { checked };
        new YiyunyingDialogBuilder(this)
            .setTitle(autoplay ? "视频自动播放网络" : "自动缓存网络")
            .setSingleChoiceItems(labels, checked, (dialog, which) -> selected[0] = which)
            .setPositiveButton("保存", (dialog, which) -> {
                if (autoplay) policy.setVideoAutoplayNetworkPolicy(NETWORK_VALUES[selected[0]]);
                else policy.setCacheNetworkPolicy(NETWORK_VALUES[selected[0]]);
                renderPolicy();
            })
            .setNegativeButton("取消", null)
            .show();
    }

    private void chooseCategories() {
        Map<String, String> labelMap = AutoCachePolicyStore.labels();
        List<String> keys = new ArrayList<>(labelMap.keySet());
        String[] labels = new String[keys.size()];
        boolean[] checked = new boolean[keys.size()];
        Set<String> selected = new LinkedHashSet<>(policy.selectedCategories());
        Set<String> allowed = policy.administratorCategories();
        for (int index = 0; index < keys.size(); index++) {
            String key = keys.get(index);
            labels[index] = labelMap.get(key) + (allowed.contains(key) ? "" : "（管理员已停用）");
            checked[index] = selected.contains(key);
        }
        new YiyunyingDialogBuilder(this)
            .setTitle("选择自动缓存内容")
            .setMultiChoiceItems(labels, checked, (dialog, which, value) -> {
                String key = keys.get(which);
                if (!allowed.contains(key)) return;
                if (value) selected.add(key); else selected.remove(key);
            })
            .setPositiveButton("保存", (dialog, which) -> {
                policy.setSelectedCategories(selected);
                renderPolicy();
            })
            .setNegativeButton("取消", null)
            .show();
    }

    private void chooseLimitKind() {
        String[] items = { "缓存容量", "自动保留时间" };
        new YiyunyingDialogBuilder(this)
            .setTitle("容量与保留时间")
            .setItems(items, (dialog, which) -> {
                if (which == 0) chooseCapacity(); else chooseRetention();
            })
            .setNegativeButton("取消", null)
            .show();
    }

    private void chooseCapacity() {
        long[] values = {
            128L * 1024L * 1024L,
            256L * 1024L * 1024L,
            512L * 1024L * 1024L,
            1024L * 1024L * 1024L,
            2L * 1024L * 1024L * 1024L
        };
        List<Long> allowed = new ArrayList<>();
        for (long value : values) if (value <= policy.administratorMaxBytes()) allowed.add(value);
        if (allowed.isEmpty()) allowed.add(policy.administratorMaxBytes());
        String[] labels = new String[allowed.size()];
        int checked = 0;
        for (int index = 0; index < allowed.size(); index++) {
            labels[index] = format(allowed.get(index));
            if (policy.maxBytes() >= allowed.get(index)) checked = index;
        }
        final int[] selected = { checked };
        new YiyunyingDialogBuilder(this)
            .setTitle("自动缓存容量上限")
            .setSingleChoiceItems(labels, checked, (dialog, which) -> selected[0] = which)
            .setPositiveButton("保存", (dialog, which) -> {
                policy.setUserMaxBytes(allowed.get(selected[0]));
                cacheManager.enforceLimits();
                renderPolicy();
                calculateSize();
            })
            .setNegativeButton("取消", null)
            .show();
    }

    private void chooseRetention() {
        int[] values = { 7, 30, 90, 180, 365, 0 };
        String[] labels = { "7 天", "30 天", "90 天", "180 天", "1 年", "一直保留" };
        int checked = 2;
        for (int index = 0; index < values.length; index++) if (values[index] == policy.retentionDays()) checked = index;
        final int[] selected = { checked };
        new YiyunyingDialogBuilder(this)
            .setTitle("自动缓存保留时间")
            .setSingleChoiceItems(labels, checked, (dialog, which) -> selected[0] = which)
            .setPositiveButton("保存", (dialog, which) -> {
                policy.setRetentionDays(values[selected[0]]);
                cacheManager.enforceLimits();
                renderPolicy();
                calculateSize();
            })
            .setNegativeButton("取消", null)
            .show();
    }

    private void calculateSize() {
        binding.progress.setVisibility(View.VISIBLE);
        executor.execute(() -> {
            long bytes = cacheManager.indexedBytes() + size(getCodeCacheDir());
            if (destroyed || Thread.currentThread().isInterrupted()) return;
            runOnUiThread(() -> {
                if (destroyed || binding == null) return;
                binding.progress.setVisibility(View.INVISIBLE);
                binding.cacheSize.setText(format(bytes));
            });
        });
    }

    private void clearCache() {
        binding.progress.setVisibility(View.VISIBLE);
        binding.clearCacheButton.setEnabled(false);
        ImageLoader.get().clear(this);
        executor.execute(() -> {
            int removed = cacheManager.clearUnprotected();
            deleteChildren(getCodeCacheDir());
            if (destroyed || Thread.currentThread().isInterrupted()) return;
            runOnUiThread(() -> {
                if (destroyed || binding == null) return;
                binding.progress.setVisibility(View.INVISIBLE);
                binding.clearCacheButton.setEnabled(true);
                Snackbar.make(binding.getRoot(), "已清理 " + removed + " 项可自动删除缓存", Snackbar.LENGTH_LONG).show();
                calculateSize();
            });
        });
    }

    private void confirmClearData() {
        new YiyunyingDialogBuilder(this)
            .setTitle("清除全部应用数据")
            .setMessage("将删除登录状态、本地草稿、缓存及本软件下载的文件。云端账号、云端消息和已上传内容不会被删除。是否继续？")
            .setPositiveButton("全部清除", (dialog, which) -> clearAllData())
            .setNegativeButton("取消", null)
            .show();
    }

    private void clearAllData() {
        DownloadManager downloads = (DownloadManager) getSystemService(DOWNLOAD_SERVICE);
        if (downloads != null) {
            for (long id : DownloadHistoryStore.allDownloadIds(this)) downloads.remove(id);
        }
        DownloadHistoryStore.clearAll(this);
        ActivityManager manager = (ActivityManager) getSystemService(ACTIVITY_SERVICE);
        if (manager == null || !manager.clearApplicationUserData()) {
            Snackbar.make(binding.getRoot(), "无法自动清除，请在系统应用设置中清除数据", Snackbar.LENGTH_LONG).show();
        }
    }

    private static long size(File file) {
        if (file == null || !file.exists()) return 0L;
        if (file.isFile()) return file.length();
        long total = 0L;
        File[] children = file.listFiles();
        if (children != null) for (File child : children) total += size(child);
        return total;
    }

    private static void deleteChildren(File directory) {
        if (directory == null || !directory.exists()) return;
        File[] children = directory.listFiles();
        if (children == null) return;
        for (File child : children) delete(child);
    }

    private static void delete(File file) {
        if (file.isDirectory()) {
            File[] children = file.listFiles();
            if (children != null) for (File child : children) delete(child);
        }
        file.delete();
    }

    private static String retentionLabel(int days) {
        return days <= 0 ? "一直" : days + " 天";
    }

    private static String format(long bytes) {
        if (bytes < 1024L) return bytes + " B";
        if (bytes < 1024L * 1024L) return String.format(Locale.CHINA, "%.1f KB", bytes / 1024d);
        if (bytes < 1024L * 1024L * 1024L) return String.format(Locale.CHINA, "%.1f MB", bytes / 1024d / 1024d);
        return String.format(Locale.CHINA, "%.2f GB", bytes / 1024d / 1024d / 1024d);
    }

    @Override protected void onDestroy() {
        destroyed = true;
        if (policyRequest != null) policyRequest.cancel();
        executor.shutdownNow();
        binding = null;
        super.onDestroy();
    }
}