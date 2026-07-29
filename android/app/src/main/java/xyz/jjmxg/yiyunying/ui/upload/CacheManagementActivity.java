package xyz.jjmxg.yiyunying.ui.upload;

import android.app.ActivityManager;
import android.app.DownloadManager;
import android.content.Context;
import android.content.Intent;
import android.os.Bundle;
import android.view.View;

import xyz.jjmxg.yiyunying.ui.common.YiyunyingDialogBuilder;
import com.google.android.material.snackbar.Snackbar;

import java.io.File;
import java.util.Locale;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

import xyz.jjmxg.yiyunying.databinding.ActivityCacheManagementBinding;
import xyz.jjmxg.yiyunying.ui.common.ImageLoader;

public final class CacheManagementActivity extends xyz.jjmxg.yiyunying.ui.common.SystemInsetActivity {
    private ActivityCacheManagementBinding binding;
    private final ExecutorService executor = Executors.newSingleThreadExecutor();
    private volatile boolean destroyed;

    public static void open(Context context) {
        context.startActivity(new Intent(context, CacheManagementActivity.class));
    }

    @Override protected void onCreate(Bundle state) {
        super.onCreate(state);
        binding = ActivityCacheManagementBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());
        binding.toolbar.setNavigationOnClickListener(view -> finish());
        binding.clearCacheButton.setOnClickListener(view -> clearCache());
        binding.downloadsButton.setOnClickListener(view -> DownloadsActivity.open(this));
        binding.clearDataButton.setOnClickListener(view -> confirmClearData());
        calculateSize();
    }

    private void calculateSize() {
        binding.progress.setVisibility(View.VISIBLE);
        File cacheDir = getCacheDir();
        File codeCacheDir = getCodeCacheDir();
        File externalCacheDir = getExternalCacheDir();
        executor.execute(() -> {
            long bytes = size(cacheDir) + size(codeCacheDir) + size(externalCacheDir);
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
        File cacheDir = getCacheDir();
        File codeCacheDir = getCodeCacheDir();
        File externalCacheDir = getExternalCacheDir();
        executor.execute(() -> {
            deleteChildren(cacheDir);
            deleteChildren(codeCacheDir);
            deleteChildren(externalCacheDir);
            if (destroyed || Thread.currentThread().isInterrupted()) return;
            runOnUiThread(() -> {
                if (destroyed || binding == null) return;
                binding.progress.setVisibility(View.INVISIBLE);
                binding.clearCacheButton.setEnabled(true);
                binding.cacheSize.setText("0 B");
                Snackbar.make(binding.getRoot(), "缓存已清理", Snackbar.LENGTH_LONG).show();
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

    private static String format(long bytes) {
        if (bytes < 1024L) return bytes + " B";
        if (bytes < 1024L * 1024L) return String.format(Locale.CHINA, "%.1f KB", bytes / 1024d);
        if (bytes < 1024L * 1024L * 1024L) return String.format(Locale.CHINA, "%.1f MB", bytes / 1024d / 1024d);
        return String.format(Locale.CHINA, "%.2f GB", bytes / 1024d / 1024d / 1024d);
    }

    @Override protected void onDestroy() {
        destroyed = true;
        executor.shutdownNow();
        binding = null;
        super.onDestroy();
    }
}
