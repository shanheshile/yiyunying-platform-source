package xyz.jjmxg.yiyunying.ui.upload;

import android.app.DownloadManager;
import android.content.Context;
import android.content.Intent;
import android.net.Uri;
import android.os.Bundle;
import android.os.Environment;
import android.media.MediaPlayer;
import android.media.PlaybackParams;
import android.media.AudioManager;
import android.view.MenuItem;
import android.view.View;
import android.view.ViewGroup;
import android.widget.ImageView;
import android.widget.VideoView;
import android.widget.MediaController;
import android.widget.LinearLayout;
import android.widget.SeekBar;
import android.widget.TextView;

import androidx.annotation.NonNull;
import androidx.activity.OnBackPressedCallback;
import androidx.appcompat.app.AppCompatActivity;
import androidx.core.view.WindowInsetsCompat;
import androidx.core.view.WindowInsetsControllerCompat;
import androidx.recyclerview.widget.RecyclerView;
import androidx.viewpager2.widget.ViewPager2;

import com.google.android.material.snackbar.Snackbar;
import xyz.jjmxg.yiyunying.ui.common.YiyunyingDialogBuilder;
import com.google.android.material.button.MaterialButton;
import com.google.gson.JsonArray;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;
import com.google.gson.JsonParser;

import java.util.ArrayList;
import java.util.HashSet;
import java.util.List;
import java.util.Set;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.data.cache.AutoCachePolicyStore;
import xyz.jjmxg.yiyunying.databinding.ActivityImageGalleryBinding;
import xyz.jjmxg.yiyunying.ui.common.ImageLoader;
import xyz.jjmxg.yiyunying.ui.common.ZoomableMediaFrame;

public final class ImageGalleryActivity extends xyz.jjmxg.yiyunying.ui.common.SystemInsetActivity {
    private static final String EXTRA_IMAGES = "images";
    private static final String EXTRA_INDEX = "index";
    private ActivityImageGalleryBinding binding;
    private final List<JsonObject> images = new ArrayList<>();
    private GalleryAdapter adapter;
    private MenuItem speedMenu;
    private MenuItem playbackMenu;
    private MenuItem fullscreenMenu;
    private MenuItem originalMenu;
    private MenuItem animationMenu;
    private final Set<Integer> originalIndexes = new HashSet<>();
    private float playbackSpeed = 1f;
    private boolean immersive;

    public static void open(Context context, List<JsonObject> images, int index) {
        JsonArray array = new JsonArray();
        for (JsonObject image : images) array.add(image);
        context.startActivity(new Intent(context, ImageGalleryActivity.class)
            .putExtra(EXTRA_IMAGES, array.toString()).putExtra(EXTRA_INDEX, index));
    }

    @Override protected void onCreate(Bundle state) {
        super.onCreate(state);
        binding = ActivityImageGalleryBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());
        binding.toolbar.setNavigationOnClickListener(view -> finish());
        try {
            JsonArray array = JsonParser.parseString(getIntent().getStringExtra(EXTRA_IMAGES)).getAsJsonArray();
            for (JsonElement value : array) if (value.isJsonObject()) images.add(value.getAsJsonObject());
        } catch (RuntimeException ignored) { }
        if (images.isEmpty()) { finish(); return; }
        MenuItem download = binding.toolbar.getMenu().add("保存到设备");
        download.setIcon(R.drawable.ic_file);
        download.setShowAsAction(MenuItem.SHOW_AS_ACTION_ALWAYS);
        originalMenu = binding.toolbar.getMenu().add("查看原图");
        originalMenu.setIcon(R.drawable.ic_album);
        originalMenu.setShowAsAction(MenuItem.SHOW_AS_ACTION_IF_ROOM);
        animationMenu = binding.toolbar.getMenu().add("播放动图");
        animationMenu.setIcon(R.drawable.ic_play);
        animationMenu.setShowAsAction(MenuItem.SHOW_AS_ACTION_IF_ROOM);
        speedMenu = binding.toolbar.getMenu().add("倍速 1.0x");
        speedMenu.setShowAsAction(MenuItem.SHOW_AS_ACTION_NEVER);
        playbackMenu = binding.toolbar.getMenu().add("亮度与音量");
        playbackMenu.setShowAsAction(MenuItem.SHOW_AS_ACTION_NEVER);
        fullscreenMenu = binding.toolbar.getMenu().add("全屏");
        fullscreenMenu.setShowAsAction(MenuItem.SHOW_AS_ACTION_NEVER);
        binding.toolbar.setOnMenuItemClickListener(item -> {
            if (item == originalMenu) loadOriginalCurrent();
            else if (item == animationMenu) replayAnimatedCurrent();
            else if (item == speedMenu) showSpeedSelector();
            else if (item == playbackMenu) showPlaybackSettings();
            else if (item == fullscreenMenu) toggleFullscreen();
            else confirmDownload();
            return true;
        });
        getOnBackPressedDispatcher().addCallback(this, new OnBackPressedCallback(true) {
            @Override public void handleOnBackPressed() {
                if (immersive) toggleFullscreen();
                else finish();
            }
        });
        adapter = new GalleryAdapter();
        binding.pager.setAdapter(adapter);
        binding.pager.registerOnPageChangeCallback(new ViewPager2.OnPageChangeCallback() {
            @Override public void onPageSelected(int position) { updateTitle(position); adapter.activate(position); }
        });
        int index = Math.max(0, Math.min(images.size() - 1, getIntent().getIntExtra(EXTRA_INDEX, 0)));
        binding.pager.setCurrentItem(index, false);
        updateTitle(index);
    }

    private void updateTitle(int position) {
        binding.toolbar.setTitle((position + 1) + " / " + images.size());
        JsonObject item = images.get(position);
        boolean video = isVideo(item);
        String name = Jsons.string(item, "file_name");
        binding.toolbar.setSubtitle(video ? (name.isEmpty() ? "视频 · " : name + " · ") + "倍速 " + speedLabel() : name);
        if (speedMenu != null) speedMenu.setVisible(video);
        if (playbackMenu != null) playbackMenu.setVisible(video);
        if (fullscreenMenu != null) fullscreenMenu.setVisible(video);
        boolean animated = !video && isAnimatedImage(item);
        String original = originalUrl(item);
        String preview = previewUrl(item);
        boolean hasSeparateOriginal = !original.isEmpty() && !original.equals(preview);
        if (originalMenu != null) {
            originalMenu.setVisible(!video && !animated);
            originalMenu.setEnabled(hasSeparateOriginal && !originalIndexes.contains(position));
            originalMenu.setTitle(originalIndexes.contains(position) || !hasSeparateOriginal ? "已是原图" : "查看原图");
        }
        if (animationMenu != null) {
            animationMenu.setVisible(animated);
            animationMenu.setTitle("重新播放动图");
        }
    }

    private void loadOriginalCurrent() {
        int position = binding.pager.getCurrentItem();
        if (position < 0 || position >= images.size()) return;
        JsonObject item = images.get(position);
        if (originalUrl(item).isEmpty()) {
            Snackbar.make(binding.getRoot(), "当前内容没有可用的原图", Snackbar.LENGTH_SHORT).show();
            return;
        }
        originalIndexes.add(position);
        adapter.notifyItemChanged(position);
        updateTitle(position);
    }

    private void replayAnimatedCurrent() {
        int position = binding.pager.getCurrentItem();
        if (position < 0 || position >= images.size()) return;
        JsonObject item = images.get(position);
        if (!isAnimatedImage(item) || originalUrl(item).isEmpty()) return;
        String source = mediaUrl(originalUrl(item));
        ImageLoader.get().invalidate(source);
        originalIndexes.add(position);
        adapter.notifyItemChanged(position);
        updateTitle(position);
    }

    private void toggleFullscreen() {
        immersive = !immersive;
        binding.toolbar.setVisibility(immersive ? View.GONE : View.VISIBLE);
        WindowInsetsControllerCompat controller = new WindowInsetsControllerCompat(getWindow(), getWindow().getDecorView());
        if (immersive) {
            controller.setSystemBarsBehavior(WindowInsetsControllerCompat.BEHAVIOR_SHOW_TRANSIENT_BARS_BY_SWIPE);
            controller.hide(WindowInsetsCompat.Type.systemBars());
        } else {
            controller.show(WindowInsetsCompat.Type.systemBars());
        }
    }

    private void showPlaybackSettings() {
        LinearLayout body = new LinearLayout(this);
        body.setOrientation(LinearLayout.VERTICAL);
        body.setPadding(dp(20), dp(8), dp(20), 0);
        TextView brightnessLabel = new TextView(this);
        brightnessLabel.setText("屏幕亮度");
        brightnessLabel.setTextSize(14);
        SeekBar brightness = new SeekBar(this);
        brightness.setMax(100);
        float currentBrightness = getWindow().getAttributes().screenBrightness;
        brightness.setProgress(currentBrightness < 0 ? 50 : Math.round(currentBrightness * 100));
        brightness.setOnSeekBarChangeListener(new SimpleSeekListener(progress -> {
            android.view.WindowManager.LayoutParams attributes = getWindow().getAttributes();
            attributes.screenBrightness = Math.max(0.02f, progress / 100f);
            getWindow().setAttributes(attributes);
        }));
        TextView volumeLabel = new TextView(this);
        volumeLabel.setText("播放音量");
        volumeLabel.setTextSize(14);
        SeekBar volume = new SeekBar(this);
        AudioManager audio = (AudioManager) getSystemService(AUDIO_SERVICE);
        int maximum = audio == null ? 15 : audio.getStreamMaxVolume(AudioManager.STREAM_MUSIC);
        volume.setMax(Math.max(1, maximum));
        volume.setProgress(audio == null ? maximum / 2 : audio.getStreamVolume(AudioManager.STREAM_MUSIC));
        volume.setOnSeekBarChangeListener(new SimpleSeekListener(progress -> {
            if (audio != null) audio.setStreamVolume(AudioManager.STREAM_MUSIC, progress, 0);
        }));
        MaterialButton speed = new MaterialButton(this);
        speed.setText("选择播放倍速 · " + speedLabel());
        speed.setOnClickListener(view -> showSpeedSelector());
        body.addView(brightnessLabel); body.addView(brightness);
        body.addView(volumeLabel); body.addView(volume); body.addView(speed);
        new YiyunyingDialogBuilder(this).setTitle("播放设置").setView(body)
            .setPositiveButton("完成", null).show();
    }

    private void showSpeedSelector() {
        String[] labels = {"0.5x", "0.75x", "1.0x", "1.25x", "1.5x", "2.0x"};
        float[] values = {0.5f, 0.75f, 1f, 1.25f, 1.5f, 2f};
        int selected = 2;
        for (int index = 0; index < values.length; index++) if (Math.abs(values[index] - playbackSpeed) < 0.01f) selected = index;
        new YiyunyingDialogBuilder(this).setTitle("播放倍速")
            .setSingleChoiceItems(labels, selected, (dialog, which) -> {
                playbackSpeed = values[which];
                if (speedMenu != null) speedMenu.setTitle("倍速 " + speedLabel());
                adapter.applySpeed(binding.pager.getCurrentItem());
                updateTitle(binding.pager.getCurrentItem());
                dialog.dismiss();
            }).setNegativeButton("取消", null).show();
    }

    private String speedLabel() { return playbackSpeed == (int) playbackSpeed ? (int) playbackSpeed + ".0x" : playbackSpeed + "x"; }

    private void confirmDownload() {
        JsonObject current = images.get(binding.pager.getCurrentItem());
        String type = Jsons.string(current, "media_type");
        new YiyunyingDialogBuilder(this)
            .setTitle("保存到设备")
            .setMessage("video".equals(type) ? "视频将保存到系统影片目录。" : "图片将保存到系统相册的易运盈目录。")
            .setPositiveButton("保存", (dialog, which) -> downloadCurrent())
            .setNegativeButton("取消", null)
            .show();
    }

    private void downloadCurrent() {
        int position = binding.pager.getCurrentItem();
        if (position < 0 || position >= images.size()) return;
        JsonObject image = images.get(position);
        String url = mediaUrl(originalUrl(image));
        String name = Jsons.string(image, "file_name");
        String type = Jsons.string(image, "media_type");
        boolean video = "video".equals(type) || Jsons.string(image, "mime_type").startsWith("video/");
        if (name.isEmpty()) name = "易运盈" + (video ? "视频_" : "图片_") + System.currentTimeMillis() + (video ? ".mp4" : ".jpg");
        try {
            DownloadManager.Request request = new DownloadManager.Request(Uri.parse(url));
            request.setTitle(name);
            request.setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED);
            request.setDestinationInExternalPublicDir(video ? Environment.DIRECTORY_MOVIES : Environment.DIRECTORY_PICTURES,
                "yyyht/" + name.replaceAll("[\\\\/:*?\"<>|]", "_"));
            DownloadManager manager = (DownloadManager) getSystemService(DOWNLOAD_SERVICE);
            if (manager != null) {
                long downloadId = manager.enqueue(request);
                DownloadHistoryStore.record(this, downloadId, name, url, video ? "视频" : "图片");
            }
            Snackbar.make(binding.getRoot(), (video ? "视频" : "图片") + "已加入保存任务", Snackbar.LENGTH_SHORT).show();
        } catch (RuntimeException exception) {
            Snackbar.make(binding.getRoot(), "保存任务创建失败", Snackbar.LENGTH_LONG).show();
        }
    }

    private final class GalleryAdapter extends RecyclerView.Adapter<GalleryAdapter.Holder> {
        private final Set<Holder> visible = new HashSet<>();

        @NonNull @Override public Holder onCreateViewHolder(@NonNull ViewGroup parent, int type) {
            ZoomableMediaFrame frame = new ZoomableMediaFrame(parent.getContext());
            frame.setLayoutParams(new ViewGroup.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT));
            ImageView image = new ImageView(parent.getContext());
            image.setLayoutParams(new ZoomableMediaFrame.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT));
            image.setScaleType(ImageView.ScaleType.FIT_CENTER);
            VideoView video = new VideoView(parent.getContext());
            video.setLayoutParams(new ZoomableMediaFrame.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT));
            frame.addView(image); frame.addView(video);
            return new Holder(frame, image, video);
        }
        @Override public void onBindViewHolder(@NonNull Holder holder, int position) {
            visible.add(holder);
            holder.frame.reset();
            JsonObject media = images.get(position);
            String selected = originalIndexes.contains(position) || isAnimatedImage(media)
                ? originalUrl(media) : previewUrl(media);
            String url = mediaUrl(selected.isEmpty() ? originalUrl(media) : selected);
            boolean video = isVideo(media);
            holder.image.setVisibility(video ? android.view.View.GONE : android.view.View.VISIBLE);
            holder.video.setVisibility(video ? android.view.View.VISIBLE : android.view.View.GONE);
            holder.frame.setZoomTarget(video ? holder.video : holder.image);
            holder.frame.setPlaybackGestureListener(null);
            holder.frame.setDoubleTapAction(video ? () -> {
                if (holder.video.isPlaying()) holder.video.pause(); else holder.video.start();
            } : null);
            holder.frame.setHoldActions(video ? () -> applyTemporarySpeed(holder.player, 2f) : null,
                video ? () -> applyPlaybackSpeed(holder.player) : null);
            if (video) {
                holder.frame.setPlaybackGestureListener(new ZoomableMediaFrame.PlaybackGestureListener() {
                    @Override public void onGestureStart() { beginPlaybackGesture(holder); }
                    @Override public void onHorizontalSeek(float distanceFraction, boolean finished) {
                        applySeekGesture(holder, distanceFraction, finished);
                    }
                    @Override public void onVerticalAdjust(boolean leftSide, float distanceFraction, boolean finished) {
                        applyLevelGesture(holder, leftSide, distanceFraction, finished);
                    }
                });
                MediaController controls = new MediaController(ImageGalleryActivity.this);
                controls.setAnchorView(holder.video);
                holder.video.setMediaController(controls);
                holder.video.setVideoURI(Uri.parse(url));
                holder.video.setOnPreparedListener(player -> {
                    holder.player = player;
                    applyPlaybackSpeed(player);
                    if (holder.getBindingAdapterPosition() == binding.pager.getCurrentItem()) {
                        if (new AutoCachePolicyStore(ImageGalleryActivity.this).videoAutoplayAllowed()) holder.video.start();
                        else holder.video.seekTo(1);
                    }
                });
                holder.video.setOnErrorListener((player, what, extra) -> {
                    Snackbar.make(binding.getRoot(), "视频暂时无法播放，请检查格式或网络", Snackbar.LENGTH_LONG).show();
                    return true;
                });
            } else {
                if (url.startsWith("content://") || url.startsWith("file://")) holder.image.setImageURI(Uri.parse(url));
                else ImageLoader.get().load(url, holder.image, R.drawable.ic_file);
            }
            holder.itemView.setOnLongClickListener(video ? null : view -> {
                int adapterPosition = holder.getBindingAdapterPosition();
                if (adapterPosition == RecyclerView.NO_POSITION) return false;
                binding.pager.setCurrentItem(adapterPosition, false);
                confirmDownload();
                return true;
            });
            holder.itemView.setOnClickListener(view -> {
                if (immersive) toggleFullscreen();
            });
        }

        private void beginPlaybackGesture(Holder holder) {
            holder.gestureStartPosition = holder.video.getCurrentPosition();
            holder.gestureDuration = Math.max(1, holder.video.getDuration());
            float brightness = getWindow().getAttributes().screenBrightness;
            holder.gestureStartBrightness = brightness < 0 ? 0.5f : brightness;
            AudioManager audio = (AudioManager) getSystemService(AUDIO_SERVICE);
            holder.gestureMaximumVolume = audio == null ? 15 : Math.max(1, audio.getStreamMaxVolume(AudioManager.STREAM_MUSIC));
            holder.gestureStartVolume = audio == null ? holder.gestureMaximumVolume / 2
                : audio.getStreamVolume(AudioManager.STREAM_MUSIC);
        }

        private void applySeekGesture(Holder holder, float fraction, boolean finished) {
            int duration = Math.max(1, holder.gestureDuration);
            int target = Math.max(0, Math.min(duration, holder.gestureStartPosition + Math.round(duration * fraction)));
            holder.video.seekTo(target);
            binding.toolbar.setSubtitle("进度 " + timeText(target) + " / " + timeText(duration));
            if (finished) updateTitle(Math.max(0, holder.getBindingAdapterPosition()));
        }

        private void applyLevelGesture(Holder holder, boolean leftSide, float fraction, boolean finished) {
            if (leftSide) {
                float target = Math.max(0.02f, Math.min(1f, holder.gestureStartBrightness + fraction));
                android.view.WindowManager.LayoutParams attributes = getWindow().getAttributes();
                attributes.screenBrightness = target;
                getWindow().setAttributes(attributes);
                binding.toolbar.setSubtitle("亮度 " + Math.round(target * 100) + "%");
            } else {
                int target = Math.max(0, Math.min(holder.gestureMaximumVolume,
                    holder.gestureStartVolume + Math.round(holder.gestureMaximumVolume * fraction)));
                AudioManager audio = (AudioManager) getSystemService(AUDIO_SERVICE);
                if (audio != null) audio.setStreamVolume(AudioManager.STREAM_MUSIC, target, 0);
                binding.toolbar.setSubtitle("音量 " + Math.round(target * 100f / holder.gestureMaximumVolume) + "%");
            }
            if (finished) updateTitle(Math.max(0, holder.getBindingAdapterPosition()));
        }
        void activate(int position) {
            Holder current = null;
            for (Holder holder : visible) {
                int adapterPosition = holder.getBindingAdapterPosition();
                if (adapterPosition == position) current = holder;
                else if (holder.video.isPlaying()) holder.video.pause();
            }
            if (current != null && isVideo(images.get(position)) && !current.video.isPlaying()
                && new AutoCachePolicyStore(ImageGalleryActivity.this).videoAutoplayAllowed()) current.video.start();
        }

        void applySpeed(int position) {
            for (Holder holder : visible) {
                if (holder.getBindingAdapterPosition() == position && holder.player != null) {
                    applyPlaybackSpeed(holder.player);
                    return;
                }
            }
        }

        @Override public void onViewRecycled(@NonNull Holder holder) {
            visible.remove(holder);
            holder.player = null;
            holder.video.stopPlayback();
            super.onViewRecycled(holder);
        }
        @Override public int getItemCount() { return images.size(); }
        final class Holder extends RecyclerView.ViewHolder {
            final ZoomableMediaFrame frame; final ImageView image; final VideoView video;
            MediaPlayer player;
            int gestureStartPosition;
            int gestureDuration = 1;
            int gestureStartVolume;
            int gestureMaximumVolume = 1;
            float gestureStartBrightness = 0.5f;
            Holder(ZoomableMediaFrame frame, ImageView image, VideoView video) {
                super(frame); this.frame = frame; this.image = image; this.video = video;
            }
        }
    }

    private void applyPlaybackSpeed(MediaPlayer player) {
        if (player == null) return;
        try { player.setPlaybackParams(new PlaybackParams().setSpeed(playbackSpeed)); }
        catch (RuntimeException exception) { Snackbar.make(binding.getRoot(), "当前视频格式不支持倍速播放", Snackbar.LENGTH_SHORT).show(); }
    }

    private void applyTemporarySpeed(MediaPlayer player, float speed) {
        if (player == null) return;
        try { player.setPlaybackParams(new PlaybackParams().setSpeed(speed)); }
        catch (RuntimeException ignored) { }
    }

    private boolean isVideo(JsonObject media) {
        return "video".equals(Jsons.string(media, "media_type")) || Jsons.string(media, "mime_type").startsWith("video/");
    }

    private boolean isAnimatedImage(JsonObject media) {
        String type = firstText(media, "media_type", "file_category", "type").toLowerCase(java.util.Locale.ROOT);
        String mime = firstText(media, "mime_type", "mime").toLowerCase(java.util.Locale.ROOT);
        String name = firstText(media, "file_name", "original_name", "name").toLowerCase(java.util.Locale.ROOT);
        return "gif".equals(type) || "image/gif".equals(mime) || booleanValue(media, "is_gif")
            || booleanValue(media, "is_animated") || name.endsWith(".gif");
    }

    private String previewUrl(JsonObject media) {
        return firstText(media, "thumbnail_url", "preview_url", "optimized_file_url", "image_url", "url");
    }

    private String originalUrl(JsonObject media) {
        return firstText(media, "original_file_url", "original_url", "file_url", "media_url",
            "download_url", "image_url", "url", "source_url");
    }

    private boolean booleanValue(JsonObject media, String key) {
        if (media.has(key) && !media.get(key).isJsonNull()) {
            try { return media.get(key).getAsBoolean(); } catch (RuntimeException ignored) { }
        }
        JsonObject metadata = Jsons.object(media, "metadata");
        if (metadata.has(key) && !metadata.get(key).isJsonNull()) {
            try { return metadata.get(key).getAsBoolean(); } catch (RuntimeException ignored) { }
        }
        return false;
    }

    private String firstText(JsonObject media, String... keys) {
        for (String key : keys) {
            String value = Jsons.string(media, key);
            if (!value.isEmpty()) return value;
        }
        JsonObject metadata = Jsons.object(media, "metadata");
        for (String key : keys) {
            String value = Jsons.string(metadata, key);
            if (!value.isEmpty()) return value;
        }
        return "";
    }

    private String mediaUrl(String value) {
        if (value == null) return "";
        if (value.startsWith("content://") || value.startsWith("file://")) return value;
        return ImageLoader.get().absoluteUrl(this, value);
    }

    private int dp(int value) { return Math.round(value * getResources().getDisplayMetrics().density); }

    private static String timeText(int milliseconds) {
        int totalSeconds = Math.max(0, milliseconds / 1000);
        return String.format(java.util.Locale.CHINA, "%02d:%02d", totalSeconds / 60, totalSeconds % 60);
    }

    private static final class SimpleSeekListener implements SeekBar.OnSeekBarChangeListener {
        interface Change { void apply(int progress); }
        private final Change change;
        SimpleSeekListener(Change change) { this.change = change; }
        @Override public void onProgressChanged(SeekBar seekBar, int progress, boolean fromUser) {
            if (fromUser) change.apply(progress);
        }
        @Override public void onStartTrackingTouch(SeekBar seekBar) { }
        @Override public void onStopTrackingTouch(SeekBar seekBar) { }
    }
}
