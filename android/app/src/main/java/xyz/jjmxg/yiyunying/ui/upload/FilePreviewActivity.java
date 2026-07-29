package xyz.jjmxg.yiyunying.ui.upload;

import android.app.DownloadManager;
import android.content.Context;
import android.content.Intent;
import android.net.Uri;
import android.os.Bundle;
import android.os.Environment;
import android.os.Handler;
import android.os.Looper;
import android.media.MediaPlayer;
import android.media.PlaybackParams;
import android.view.MenuItem;
import android.view.View;
import android.webkit.WebViewClient;
import android.widget.MediaController;
import android.widget.SeekBar;

import androidx.appcompat.app.AppCompatActivity;

import com.google.android.material.snackbar.Snackbar;
import xyz.jjmxg.yiyunying.ui.common.YiyunyingDialogBuilder;
import com.google.gson.JsonObject;
import com.google.gson.JsonParser;

import java.io.IOException;
import java.util.Locale;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.core.AppAccess;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.data.api.RequestHandle;
import xyz.jjmxg.yiyunying.databinding.ActivityFilePreviewBinding;
import xyz.jjmxg.yiyunying.domain.Role;
import xyz.jjmxg.yiyunying.ui.common.ImageLoader;

public final class FilePreviewActivity extends xyz.jjmxg.yiyunying.ui.common.SystemInsetActivity {
    private static final String EXTRA_FILE = "file";
    private ActivityFilePreviewBinding binding;
    private JsonObject file;
    private MenuItem speedMenu;
    private MenuItem favoriteMenu;
    private RequestHandle favoriteRequest;
    private MediaPlayer mediaPlayer;
    private float playbackSpeed = 1f;
    private boolean audioMode;
    private final Handler progressHandler = new Handler(Looper.getMainLooper());
    private final Runnable updateAudioProgress = new Runnable() {
        @Override public void run() {
            if (binding == null || !audioMode || mediaPlayer == null) return;
            try {
                int duration = Math.max(0, mediaPlayer.getDuration());
                int position = Math.max(0, mediaPlayer.getCurrentPosition());
                binding.audioSeek.setMax(duration);
                binding.audioSeek.setProgress(position);
                binding.audioTime.setText(formatTime(position) + " / " + formatTime(duration));
                binding.audioPlay.setText(mediaPlayer.isPlaying() ? "暂停播放" : "继续播放");
            } catch (IllegalStateException ignored) { }
            progressHandler.postDelayed(this, 400L);
        }
    };

    public static void open(Context context, JsonObject file) {
        context.startActivity(new Intent(context, FilePreviewActivity.class).putExtra(EXTRA_FILE, file.toString()));
    }

    public static void open(Context context, String name, String url, String mime) {
        JsonObject file = new JsonObject();
        file.addProperty("original_name", name); file.addProperty("file_url", url); file.addProperty("mime_type", mime);
        open(context, file);
    }

    @Override protected void onCreate(Bundle state) {
        super.onCreate(state);
        binding = ActivityFilePreviewBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());
        try { file = JsonParser.parseString(getIntent().getStringExtra(EXTRA_FILE)).getAsJsonObject(); }
        catch (RuntimeException exception) { file = new JsonObject(); }
        xyz.jjmxg.yiyunying.core.RuntimeLanguage.setDynamicToolbarTitle(
            binding.toolbar,
            Jsons.string(file, "original_name")
        );
        binding.toolbar.setNavigationOnClickListener(view -> finish());
        MenuItem download = binding.toolbar.getMenu().add("保存到设备");
        download.setIcon(R.drawable.ic_file);
        download.setShowAsAction(MenuItem.SHOW_AS_ACTION_ALWAYS);
        speedMenu = binding.toolbar.getMenu().add("倍速 1.0x");
        speedMenu.setVisible(false);
        speedMenu.setShowAsAction(MenuItem.SHOW_AS_ACTION_NEVER);
        long uploadId = Jsons.longValue(file, "id");
        if (uploadId <= 0) uploadId = Jsons.longValue(file, "target_id");
        if (AppAccess.from(this).session().role() == Role.USER && uploadId > 0) {
            favoriteMenu = binding.toolbar.getMenu().add(flag(file, "favorited") ? "取消收藏" : "收藏");
            favoriteMenu.setIcon(R.drawable.ic_favorite);
            favoriteMenu.setShowAsAction(MenuItem.SHOW_AS_ACTION_ALWAYS);
        }
        binding.toolbar.setOnMenuItemClickListener(item -> {
            if (item == speedMenu) showSpeedSelector();
            else if (item == favoriteMenu) toggleFavorite();
            else confirmDownload();
            return true;
        });
        binding.image.setOnLongClickListener(view -> { confirmDownload(); return true; });
        binding.audioPlay.setOnClickListener(view -> toggleAudioPlayback());
        binding.audioSeek.setOnSeekBarChangeListener(new SeekBar.OnSeekBarChangeListener() {
            @Override public void onProgressChanged(SeekBar seekBar, int progress, boolean fromUser) {
                if (fromUser && audioMode && mediaPlayer != null) {
                    try { mediaPlayer.seekTo(progress); } catch (IllegalStateException ignored) { }
                }
            }
            @Override public void onStartTrackingTouch(SeekBar seekBar) { }
            @Override public void onStopTrackingTouch(SeekBar seekBar) { }
        });
        render();
    }

    private void render() {
        String url = previewUrl(Jsons.string(file, "file_url"));
        if (url.isEmpty()) url = previewUrl(Jsons.string(file, "preview_url"));
        String mime = Jsons.string(file, "mime_type").toLowerCase();
        String category = Jsons.string(file, "file_category");
        if (mime.startsWith("image/") || "image".equals(category)) {
            binding.image.setVisibility(View.VISIBLE);
            binding.content.setZoomTarget(binding.image);
            ImageLoader.get().load(url, binding.image, R.drawable.ic_file);
            binding.image.postDelayed(() -> binding.progress.setVisibility(View.INVISIBLE), 350L);
            return;
        }
        if (mime.startsWith("audio/") || "audio".equals(category)) {
            renderAudio(url);
            return;
        }
        if (mime.startsWith("video/") || "video".equals(category)) {
            audioMode = false;
            binding.video.setVisibility(View.VISIBLE);
            binding.content.setZoomTarget(binding.video);
            speedMenu.setVisible(true);
            MediaController controls = new MediaController(this);
            controls.setAnchorView(binding.video);
            binding.video.setMediaController(controls);
            binding.video.setVideoURI(Uri.parse(url));
            binding.video.setOnPreparedListener(player -> {
                mediaPlayer = player;
                applyPlaybackSpeed();
                binding.progress.setVisibility(View.INVISIBLE);
                binding.video.start();
            });
            binding.video.setOnErrorListener((player, what, extra) -> { showUnsupported(); return true; });
            return;
        }
        if (mime.startsWith("text/") || mime.contains("pdf") || mime.contains("json") || mime.contains("document") || "document".equals(category)) {
            binding.web.setVisibility(View.VISIBLE);
            binding.web.setWebViewClient(new WebViewClient() {
                @Override public void onPageFinished(android.webkit.WebView view, String loadedUrl) { binding.progress.setVisibility(View.INVISIBLE); }
            });
            binding.web.getSettings().setBuiltInZoomControls(true);
            binding.web.getSettings().setDisplayZoomControls(false);
            binding.web.loadUrl(url);
            return;
        }
        showUnsupported();
    }

    private String previewUrl(String raw) {
        if (raw == null) return "";
        String value = raw.trim();
        if (value.startsWith("content://") || value.startsWith("file://")) return value;
        return ImageLoader.get().absoluteUrl(this, value);
    }

    private void renderAudio(String url) {
        audioMode = true;
        binding.audioPanel.setVisibility(View.VISIBLE);
        binding.audioTitle.setText(Jsons.string(file, "original_name").isEmpty() ? "音频文件" : Jsons.string(file, "original_name"));
        binding.content.setZoomTarget(null);
        speedMenu.setVisible(true);
        mediaPlayer = new MediaPlayer();
        try {
            mediaPlayer.setDataSource(this, Uri.parse(url));
            mediaPlayer.setOnPreparedListener(player -> {
                applyPlaybackSpeed();
                binding.progress.setVisibility(View.INVISIBLE);
                binding.audioSeek.setMax(Math.max(0, player.getDuration()));
                player.start();
                progressHandler.removeCallbacks(updateAudioProgress);
                progressHandler.post(updateAudioProgress);
            });
            mediaPlayer.setOnCompletionListener(player -> {
                binding.audioPlay.setText("重新播放");
                binding.audioSeek.setProgress(binding.audioSeek.getMax());
            });
            mediaPlayer.setOnErrorListener((player, what, extra) -> { showUnsupported(); return true; });
            mediaPlayer.prepareAsync();
        } catch (IOException | RuntimeException exception) {
            showUnsupported();
        }
    }

    private void toggleAudioPlayback() {
        if (!audioMode || mediaPlayer == null) return;
        try {
            if (mediaPlayer.isPlaying()) mediaPlayer.pause();
            else {
                if (mediaPlayer.getCurrentPosition() >= Math.max(0, mediaPlayer.getDuration() - 250)) mediaPlayer.seekTo(0);
                mediaPlayer.start();
            }
            binding.audioPlay.setText(mediaPlayer.isPlaying() ? "暂停播放" : "继续播放");
        } catch (IllegalStateException ignored) { }
    }

    private void showSpeedSelector() {
        String[] labels = {"0.5x", "0.75x", "1.0x", "1.25x", "1.5x", "2.0x"};
        float[] values = {0.5f, 0.75f, 1f, 1.25f, 1.5f, 2f};
        int selected = 2;
        for (int index = 0; index < values.length; index++) if (Math.abs(values[index] - playbackSpeed) < 0.01f) selected = index;
        new YiyunyingDialogBuilder(this).setTitle("播放倍速")
            .setSingleChoiceItems(labels, selected, (dialog, which) -> {
                playbackSpeed = values[which];
                speedMenu.setTitle("倍速 " + speedLabel());
                applyPlaybackSpeed();
                dialog.dismiss();
            }).setNegativeButton("取消", null).show();
    }

    private void applyPlaybackSpeed() {
        if (mediaPlayer == null) return;
        try { mediaPlayer.setPlaybackParams(new PlaybackParams().setSpeed(playbackSpeed)); }
        catch (RuntimeException exception) { Snackbar.make(binding.getRoot(), "当前媒体格式不支持倍速播放", Snackbar.LENGTH_SHORT).show(); }
    }

    private String speedLabel() {
        return playbackSpeed == (int) playbackSpeed ? (int) playbackSpeed + ".0x" : playbackSpeed + "x";
    }

    private static String formatTime(int milliseconds) {
        int totalSeconds = Math.max(0, milliseconds / 1000);
        return String.format(Locale.CHINA, "%02d:%02d", totalSeconds / 60, totalSeconds % 60);
    }

    private void showUnsupported() {
        binding.content.setZoomTarget(null);
        binding.progress.setVisibility(View.INVISIBLE);
        binding.video.setVisibility(View.GONE);
        binding.audioPanel.setVisibility(View.GONE);
        binding.web.setVisibility(View.GONE);
        binding.image.setVisibility(View.GONE);
        binding.unsupported.setVisibility(View.VISIBLE);
    }

    private void toggleFavorite() {
        if (favoriteRequest != null || favoriteMenu == null) return;
        long id = Jsons.longValue(file, "id");
        if (id <= 0) id = Jsons.longValue(file, "target_id");
        if (id <= 0) {
            Snackbar.make(binding.getRoot(), "文件信息不完整，暂时无法收藏", Snackbar.LENGTH_LONG).show();
            return;
        }
        favoriteMenu.setEnabled(false);
        favoriteRequest = AppAccess.from(this).repository().post(
            "/api/user/uploads/" + id + "/favorite", new JsonObject(), result -> {
                favoriteRequest = null;
                if (binding == null || isFinishing() || isDestroyed()) return;
                favoriteMenu.setEnabled(true);
                if (result.isAuthenticationFailure()) {
                    Snackbar.make(binding.getRoot(), "登录状态已失效，请重新登录", Snackbar.LENGTH_LONG).show();
                    return;
                }
                if (!result.isSuccessful()) {
                    Snackbar.make(binding.getRoot(), result.message().isEmpty() ? "收藏操作失败" : result.message(), Snackbar.LENGTH_LONG).show();
                    return;
                }
                boolean active = flag(result.dataObject(), "favorited");
                file.addProperty("favorited", active);
                favoriteMenu.setTitle(active ? "取消收藏" : "收藏");
                Snackbar.make(binding.getRoot(), active ? "文件已收藏" : "已取消收藏文件", Snackbar.LENGTH_SHORT).show();
            });
    }

    private static boolean flag(JsonObject source, String key) {
        return source != null && source.has(key) && !source.get(key).isJsonNull()
            && source.get(key).getAsBoolean();
    }
    private void confirmDownload() {
        new YiyunyingDialogBuilder(this)
            .setTitle("保存到设备")
            .setMessage("图片、视频会保存到系统媒体目录；文档会保存到 Documents/yyyht。")
            .setPositiveButton("保存", (dialog, which) -> download())
            .setNegativeButton("取消", null)
            .show();
    }

    private void download() {
        String url = ImageLoader.get().absoluteUrl(this, Jsons.string(file, "file_url"));
        if (url.isEmpty()) url = ImageLoader.get().absoluteUrl(this, Jsons.string(file, "preview_url"));
        if (url.isEmpty()) { Snackbar.make(binding.getRoot(), "文件地址无效", Snackbar.LENGTH_LONG).show(); return; }
        try {
            DownloadManager.Request request = new DownloadManager.Request(Uri.parse(url));
            request.setTitle(Jsons.string(file, "original_name"));
            request.setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED);
            String mime = Jsons.string(file, "mime_type").toLowerCase();
            String directory = mime.startsWith("image/") ? Environment.DIRECTORY_PICTURES
                : (mime.startsWith("video/") ? Environment.DIRECTORY_MOVIES
                : (mime.startsWith("audio/") ? Environment.DIRECTORY_MUSIC : Environment.DIRECTORY_DOCUMENTS));
            String name = Jsons.string(file, "original_name");
            if (name.isEmpty()) name = "易运盈文件_" + System.currentTimeMillis();
            request.setDestinationInExternalPublicDir(directory,
                "yyyht/" + name.replaceAll("[\\\\/:*?\"<>|]", "_"));
            DownloadManager manager = (DownloadManager) getSystemService(DOWNLOAD_SERVICE);
            if (manager != null) {
                long downloadId = manager.enqueue(request);
                DownloadHistoryStore.record(this, downloadId, name, url,
                    mime.startsWith("image/") ? "图片" : (mime.startsWith("video/") ? "视频" : (mime.startsWith("audio/") ? "音频" : "文档")));
            }
            Snackbar.make(binding.getRoot(), "已加入下载任务", Snackbar.LENGTH_SHORT).show();
        } catch (RuntimeException exception) {
            Snackbar.make(binding.getRoot(), "下载任务创建失败", Snackbar.LENGTH_LONG).show();
        }
    }

    @Override protected void onDestroy() {
        if (favoriteRequest != null) favoriteRequest.cancel();
        favoriteRequest = null;
        progressHandler.removeCallbacks(updateAudioProgress);
        if (audioMode && mediaPlayer != null) {
            try { mediaPlayer.release(); } catch (RuntimeException ignored) { }
        }
        mediaPlayer = null;
        binding.video.stopPlayback();
        binding.web.stopLoading();
        binding.web.destroy();
        super.onDestroy();
    }
}
