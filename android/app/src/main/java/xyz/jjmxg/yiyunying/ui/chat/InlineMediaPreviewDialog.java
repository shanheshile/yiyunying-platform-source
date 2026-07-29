package xyz.jjmxg.yiyunying.ui.chat;

import android.app.Dialog;
import android.content.Context;
import android.graphics.Color;
import android.media.MediaPlayer;
import android.media.AudioManager;
import android.os.Handler;
import android.os.Looper;
import android.os.Build;
import android.view.GestureDetector;
import android.view.Gravity;
import android.view.MotionEvent;
import android.view.ScaleGestureDetector;
import android.view.View;
import android.view.ViewGroup;
import android.view.Window;
import android.view.WindowManager;
import android.widget.FrameLayout;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.ProgressBar;
import android.widget.SeekBar;
import android.widget.TextView;
import android.widget.Toast;
import android.widget.VideoView;

import com.google.android.material.button.MaterialButton;
import com.google.gson.JsonObject;

import java.util.ArrayList;
import java.util.List;
import java.util.Locale;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.ui.common.ImageLoader;
import xyz.jjmxg.yiyunying.ui.common.RecordDetailDialog;

public final class InlineMediaPreviewDialog {
    private InlineMediaPreviewDialog() { }

    public static void show(Context context, List<JsonObject> source, int selectedIndex) {
        if (source == null || source.isEmpty()) return;
        new Preview(context, source, selectedIndex).show();
    }

    private static final class Preview {
        private final Context context;
        private final List<JsonObject> items = new ArrayList<>();
        private final Handler handler = new Handler(Looper.getMainLooper());
        private final Dialog dialog;
        private final FrameLayout stage;
        private final TextView title;
        private final TextView time;
        private final SeekBar seek;
        private final MaterialButton speed;
        private final MaterialButton fullscreen;
        private final MaterialButton previous;
        private final MaterialButton next;
        private int index;
        private float playbackSpeed = 1f;
        private AspectRatioVideoView video;
        private MediaPlayer videoPlayer;
        private ProgressBar buffering;
        private boolean temporaryFast;
        private boolean immersive;
        private final float initialBrightness;

        Preview(Context context, List<JsonObject> source, int selectedIndex) {
            this.context = context;
            initialBrightness = context instanceof android.app.Activity
                ? ((android.app.Activity) context).getWindow().getAttributes().screenBrightness : -1f;
            for (JsonObject item : source) if (item != null) items.add(item.deepCopy());
            index = Math.max(0, Math.min(items.size() - 1, selectedIndex));
            dialog = new Dialog(context, android.R.style.Theme_Material_NoActionBar_Fullscreen);
            LinearLayout root = new LinearLayout(context);
            root.setOrientation(LinearLayout.VERTICAL);
            root.setBackgroundColor(Color.BLACK);

            LinearLayout toolbar = new LinearLayout(context);
            toolbar.setGravity(Gravity.CENTER_VERTICAL);
            toolbar.setPadding(dp(8), dp(8), dp(8), dp(6));
            MaterialButton close = iconButton(R.drawable.ic_close, "关闭预览");
            close.setOnClickListener(view -> dialog.dismiss());
            title = new TextView(context);
            title.setTextColor(Color.WHITE);
            title.setTextSize(15);
            title.setGravity(Gravity.CENTER);
            toolbar.addView(close, new LinearLayout.LayoutParams(dp(52), dp(48)));
            toolbar.addView(title, new LinearLayout.LayoutParams(0, dp(48), 1f));
            MaterialButton info = iconButton(R.drawable.ic_file, "查看媒体信息");
            info.setOnClickListener(view -> showMediaInfo());
            toolbar.addView(info, new LinearLayout.LayoutParams(dp(52), dp(48)));
            root.addView(toolbar, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, dp(62)));

            stage = new FrameLayout(context);
            root.addView(stage, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, 0, 1f));

            previous = iconButton(R.drawable.ic_arrow_left, "上一项");
            previous.setBackgroundColor(Color.argb(132, 0, 0, 0));
            previous.setOnClickListener(view -> move(-1));
            next = iconButton(R.drawable.ic_arrow_right, "下一项");
            next.setBackgroundColor(Color.argb(132, 0, 0, 0));
            next.setOnClickListener(view -> move(1));

            LinearLayout controls = new LinearLayout(context);
            controls.setGravity(Gravity.CENTER_VERTICAL);
            controls.setPadding(dp(12), dp(6), dp(12), dp(10));
            seek = new SeekBar(context);
            seek.setVisibility(View.GONE);
            seek.setOnSeekBarChangeListener(new SeekBar.OnSeekBarChangeListener() {
                @Override public void onProgressChanged(SeekBar bar, int progress, boolean fromUser) {
                    if (fromUser && video != null) video.seekTo(progress);
                    updateTime(progress);
                }
                @Override public void onStartTrackingTouch(SeekBar bar) { }
                @Override public void onStopTrackingTouch(SeekBar bar) { }
            });
            time = new TextView(context);
            time.setTextColor(Color.LTGRAY);
            time.setTextSize(12);
            time.setGravity(Gravity.CENTER);
            time.setVisibility(View.GONE);
            speed = textButton("1.0×");
            speed.setVisibility(View.GONE);
            speed.setOnClickListener(view -> cycleSpeed());
            fullscreen = iconButton(R.drawable.ic_fullscreen, "全屏播放");
            fullscreen.setVisibility(View.GONE);
            fullscreen.setOnClickListener(view -> toggleImmersive());
            controls.addView(seek, new LinearLayout.LayoutParams(0, dp(48), 1f));
            controls.addView(time, new LinearLayout.LayoutParams(dp(88), dp(48)));
            controls.addView(speed, new LinearLayout.LayoutParams(dp(68), dp(48)));
            controls.addView(fullscreen, new LinearLayout.LayoutParams(dp(52), dp(48)));
            root.addView(controls, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, dp(66)));

            GestureDetector swipe = new GestureDetector(context, new GestureDetector.SimpleOnGestureListener() {
                @Override public boolean onDown(MotionEvent event) { return true; }
                @Override public boolean onFling(MotionEvent down, MotionEvent up, float velocityX, float velocityY) {
                    if (down == null || up == null || Math.abs(up.getX() - down.getX()) < dp(72)
                        || Math.abs(velocityX) < Math.abs(velocityY)) return false;
                    move(up.getX() < down.getX() ? 1 : -1);
                    return true;
                }
            });
            stage.setOnTouchListener((view, event) -> swipe.onTouchEvent(event));
            dialog.setContentView(root);
            dialog.setOnDismissListener(ignored -> {
                releaseVideo();
                setImmersive(false);
                restoreBrightness();
            });
        }

        void show() {
            dialog.show();
            Window window = dialog.getWindow();
            if (window != null) {
                window.setLayout(WindowManager.LayoutParams.MATCH_PARENT, WindowManager.LayoutParams.MATCH_PARENT);
                window.setStatusBarColor(Color.BLACK);
                window.setNavigationBarColor(Color.BLACK);
            }
            render();
        }

        private void render() {
            releaseVideo();
            stage.removeAllViews();
            JsonObject item = items.get(index);
            String type = Jsons.string(item, "media_type");
            title.setText((index + 1) + " / " + items.size() + "  " + mediaTypeLabel(type));
            if ("video".equals(type)) renderVideo(item); else renderImage(item);
            FrameLayout.LayoutParams previousParams = new FrameLayout.LayoutParams(dp(48), dp(58), Gravity.START | Gravity.CENTER_VERTICAL);
            previousParams.leftMargin = dp(8);
            stage.addView(previous, previousParams);
            FrameLayout.LayoutParams nextParams = new FrameLayout.LayoutParams(dp(48), dp(58), Gravity.END | Gravity.CENTER_VERTICAL);
            nextParams.rightMargin = dp(8);
            stage.addView(next, nextParams);
            previous.setVisibility(index > 0 ? View.VISIBLE : View.INVISIBLE);
            next.setVisibility(index + 1 < items.size() ? View.VISIBLE : View.INVISIBLE);
        }

        private void renderImage(JsonObject item) {
            seek.setVisibility(View.GONE);
            time.setVisibility(View.GONE);
            speed.setVisibility(View.GONE);
            fullscreen.setVisibility(View.GONE);
            ZoomImageView image = new ZoomImageView(context, this::move);
            image.setBackgroundColor(Color.BLACK);
            image.setScaleType(ImageView.ScaleType.FIT_CENTER);
            stage.addView(image, new FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT));
            ImageLoader.get().load(absolute(item), image, R.drawable.ic_file);
        }

        private void renderVideo(JsonObject item) {
            seek.setVisibility(View.VISIBLE);
            time.setVisibility(View.VISIBLE);
            speed.setVisibility(View.VISIBLE);
            fullscreen.setVisibility(View.VISIBLE);
            playbackSpeed = 1f;
            speed.setText("1.0×");
            video = new AspectRatioVideoView(context);
            video.setBackgroundColor(Color.BLACK);
            FrameLayout.LayoutParams params = new FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT, Gravity.CENTER);
            params.leftMargin = dp(18);
            params.rightMargin = dp(18);
            params.topMargin = dp(12);
            params.bottomMargin = dp(12);
            stage.addView(video, params);

            buffering = new ProgressBar(context);
            buffering.setIndeterminate(true);
            FrameLayout.LayoutParams bufferingParams = new FrameLayout.LayoutParams(dp(44), dp(44), Gravity.CENTER);
            stage.addView(buffering, bufferingParams);

            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                video.setAudioFocusRequest(AudioManager.AUDIOFOCUS_GAIN);
            }
            video.setVideoPath(absolute(item));
            video.setOnPreparedListener(player -> {
                videoPlayer = player;
                player.setLooping(false);
                try {
                    player.setVideoScalingMode(MediaPlayer.VIDEO_SCALING_MODE_SCALE_TO_FIT);
                } catch (RuntimeException ignored) { }
                video.setVideoDimensions(player.getVideoWidth(), player.getVideoHeight());
                seek.setMax(Math.max(1, player.getDuration()));
                player.setOnBufferingUpdateListener((mediaPlayer, percent) ->
                    seek.setSecondaryProgress(Math.max(0, Math.min(seek.getMax(), seek.getMax() * percent / 100))));
                player.setOnInfoListener((mediaPlayer, what, extra) -> {
                    if (buffering == null) return false;
                    if (what == MediaPlayer.MEDIA_INFO_BUFFERING_START) buffering.setVisibility(View.VISIBLE);
                    if (what == MediaPlayer.MEDIA_INFO_BUFFERING_END
                        || what == MediaPlayer.MEDIA_INFO_VIDEO_RENDERING_START) {
                        buffering.setVisibility(View.GONE);
                    }
                    return false;
                });
                if (buffering != null) buffering.setVisibility(View.GONE);
                video.start();
                handler.removeCallbacks(tick);
                handler.post(tick);
            });
            video.setOnErrorListener((player, what, extra) -> {
                if (buffering != null) buffering.setVisibility(View.GONE);
                handler.removeCallbacks(tick);
                Toast.makeText(context, "视频暂时无法播放，请检查网络后重试", Toast.LENGTH_SHORT).show();
                return true;
            });
            video.setOnCompletionListener(player -> {
                handler.removeCallbacks(tick);
                seek.setProgress(seek.getMax());
                updateTime(seek.getMax());
            });
            GestureDetector gestures = new GestureDetector(context, new GestureDetector.SimpleOnGestureListener() {
                @Override public boolean onDown(MotionEvent event) { return true; }
                @Override public boolean onSingleTapConfirmed(MotionEvent event) {
                    if (video.isPlaying()) {
                        video.pause();
                    } else {
                        video.start();
                        handler.removeCallbacks(tick);
                        handler.post(tick);
                    }
                    return true;
                }
                @Override public void onLongPress(MotionEvent event) {
                    temporaryFast = true;
                    applyVideoSpeed(2f);
                }
                @Override public boolean onScroll(MotionEvent first, MotionEvent current, float distanceX, float distanceY) {
                    if (first == null || current == null || video == null) return false;
                    if (Math.abs(distanceX) > Math.abs(distanceY)) {
                        int target = Math.max(0, Math.min(video.getDuration(), video.getCurrentPosition() + Math.round(distanceX * 28f)));
                        video.seekTo(target);
                        seek.setProgress(target);
                        updateTime(target);
                    } else if (first.getX() < stage.getWidth() / 2f) {
                        adjustBrightness(distanceY / Math.max(1f, stage.getHeight()));
                    } else {
                        adjustVolume(distanceY);
                    }
                    return true;
                }
            });
            video.setOnTouchListener((view, event) -> {
                boolean handled = gestures.onTouchEvent(event);
                if ((event.getActionMasked() == MotionEvent.ACTION_UP || event.getActionMasked() == MotionEvent.ACTION_CANCEL)
                    && temporaryFast) {
                    temporaryFast = false;
                    applyVideoSpeed(playbackSpeed);
                }
                return handled;
            });
        }

        private final Runnable tick = new Runnable() {
            @Override public void run() {
                if (video == null) return;
                try {
                    seek.setProgress(video.getCurrentPosition());
                    updateTime(video.getCurrentPosition());
                    handler.postDelayed(this, 350L);
                } catch (RuntimeException ignored) { }
            }
        };

        private void cycleSpeed() {
            float[] values = {0.5f, 1f, 1.5f, 2f};
            int current = 0;
            for (int i = 0; i < values.length; i++) if (Math.abs(values[i] - playbackSpeed) < 0.01f) current = i;
            playbackSpeed = values[(current + 1) % values.length];
            speed.setText(String.format(Locale.CHINA, "%.1f×", playbackSpeed));
            applyVideoSpeed(playbackSpeed);
        }

        private void applyVideoSpeed(float value) {
            if (videoPlayer != null && android.os.Build.VERSION.SDK_INT >= 23) {
                try { videoPlayer.setPlaybackParams(videoPlayer.getPlaybackParams().setSpeed(value)); }
                catch (RuntimeException ignored) { }
            }
        }

        private void toggleImmersive() {
            setImmersive(!immersive);
        }

        private void setImmersive(boolean enabled) {
            Window window = dialog.getWindow();
            if (window == null) return;
            immersive = enabled;
            fullscreen.setIconResource(enabled ? R.drawable.ic_fullscreen_exit : R.drawable.ic_fullscreen);
            fullscreen.setContentDescription(enabled ? "退出全屏" : "全屏播放");
            if (Build.VERSION.SDK_INT >= 30) {
                android.view.WindowInsetsController controller = window.getInsetsController();
                if (controller == null) return;
                int bars = android.view.WindowInsets.Type.statusBars() | android.view.WindowInsets.Type.navigationBars();
                if (enabled) {
                    controller.hide(bars);
                    controller.setSystemBarsBehavior(
                        android.view.WindowInsetsController.BEHAVIOR_SHOW_TRANSIENT_BARS_BY_SWIPE);
                } else {
                    controller.show(bars);
                }
                return;
            }
            window.getDecorView().setSystemUiVisibility(enabled
                ? View.SYSTEM_UI_FLAG_FULLSCREEN
                    | View.SYSTEM_UI_FLAG_HIDE_NAVIGATION
                    | View.SYSTEM_UI_FLAG_IMMERSIVE_STICKY
                    | View.SYSTEM_UI_FLAG_LAYOUT_FULLSCREEN
                    | View.SYSTEM_UI_FLAG_LAYOUT_HIDE_NAVIGATION
                    | View.SYSTEM_UI_FLAG_LAYOUT_STABLE
                : View.SYSTEM_UI_FLAG_LAYOUT_STABLE);
        }

        private void adjustBrightness(float delta) {
            if (!(context instanceof android.app.Activity)) return;
            Window window = ((android.app.Activity) context).getWindow();
            WindowManager.LayoutParams params = window.getAttributes();
            float current = params.screenBrightness < 0 ? 0.5f : params.screenBrightness;
            params.screenBrightness = Math.max(0.05f, Math.min(1f, current + delta * 2f));
            window.setAttributes(params);
        }

        private void adjustVolume(float distanceY) {
            AudioManager audio = (AudioManager) context.getSystemService(Context.AUDIO_SERVICE);
            if (audio == null || Math.abs(distanceY) < dp(8)) return;
            audio.adjustStreamVolume(AudioManager.STREAM_MUSIC,
                distanceY > 0 ? AudioManager.ADJUST_RAISE : AudioManager.ADJUST_LOWER, 0);
        }

        private void restoreBrightness() {
            if (!(context instanceof android.app.Activity)) return;
            Window window = ((android.app.Activity) context).getWindow();
            WindowManager.LayoutParams params = window.getAttributes();
            params.screenBrightness = initialBrightness;
            window.setAttributes(params);
        }

        private void updateTime(long current) {
            int duration = video == null ? seek.getMax() : video.getDuration();
            time.setText(format(current) + " / " + format(duration));
        }

        private String format(long millis) {
            long seconds = Math.max(0, millis / 1000L);
            return String.format(Locale.CHINA, "%02d:%02d", seconds / 60L, seconds % 60L);
        }

        private void move(int direction) {
            int target = index + direction;
            if (target < 0 || target >= items.size()) return;
            float distance = dp(34) * (direction > 0 ? -1f : 1f);
            stage.animate().cancel();
            stage.animate().alpha(0.15f).translationX(distance).setDuration(105L).withEndAction(() -> {
                index = target;
                render();
                stage.setTranslationX(-distance);
                stage.animate().alpha(1f).translationX(0f).setDuration(145L).start();
            }).start();
        }

        private void showMediaInfo() {
            JsonObject item = items.get(index);
            String name = Jsons.string(item, "file_name");
            if (name.isEmpty()) name = Jsons.string(item, "name");
            if (name.isEmpty()) name = "未命名媒体";
            long bytes = Jsons.longValue(item, "size_bytes");
            long width = Jsons.longValue(item, "width");
            long height = Jsons.longValue(item, "height");
            JsonObject detail = new JsonObject();
            detail.addProperty("original_name", name);
            detail.addProperty("file_category_name", mediaTypeLabel(Jsons.string(item, "media_type")));
            if (bytes > 0) detail.addProperty("size_bytes", bytes);
            if (width > 0 && height > 0) detail.addProperty("dimensions", width + " × " + height);
            long duration = Jsons.longValue(item, "duration_ms");
            if (duration > 0) detail.addProperty("duration_ms", duration);
            RecordDetailDialog.show(context, "媒体信息", detail);
        }

        private String mediaTypeLabel(String type) {
            if ("sticker".equals(type)) return "表情包";
            if ("video".equals(type)) return "视频";
            if ("gif".equals(type) || "motion_photo".equals(type)) return "动图";
            return "图片";
        }

        private String sizeText(long bytes) {
            if (bytes >= 1073741824L) return String.format(Locale.CHINA, "%.2f GB", bytes / 1073741824d);
            if (bytes >= 1048576L) return String.format(Locale.CHINA, "%.2f MB", bytes / 1048576d);
            if (bytes >= 1024L) return String.format(Locale.CHINA, "%.1f KB", bytes / 1024d);
            return bytes + " B";
        }

        private String absolute(JsonObject item) {
            return ImageLoader.get().absoluteUrl(context, Jsons.string(item, "url"));
        }

        private MaterialButton textButton(String text) {
            MaterialButton button = new MaterialButton(context);
            button.setText(text);
            button.setTextColor(Color.WHITE);
            button.setBackgroundColor(Color.TRANSPARENT);
            button.setMinWidth(0);
            return button;
        }

        private MaterialButton iconButton(int icon, String description) {
            MaterialButton button = new MaterialButton(context, null, com.google.android.material.R.attr.materialIconButtonStyle);
            button.setIconResource(icon);
            button.setIconTint(android.content.res.ColorStateList.valueOf(Color.WHITE));
            button.setContentDescription(description);
            button.setBackgroundColor(Color.TRANSPARENT);
            button.setMinWidth(0);
            return button;
        }

        private int dp(int value) {
            return Math.round(value * context.getResources().getDisplayMetrics().density);
        }

        private void releaseVideo() {
            handler.removeCallbacks(tick);
            if (video != null) {
                try {
                    video.setOnPreparedListener(null);
                    video.setOnCompletionListener(null);
                    video.setOnErrorListener(null);
                    video.stopPlayback();
                } catch (RuntimeException ignored) { }
                video = null;
                videoPlayer = null;
            }
            buffering = null;
        }
    }

    private static final class AspectRatioVideoView extends VideoView {
        private int videoWidth = 16;
        private int videoHeight = 9;

        AspectRatioVideoView(Context context) {
            super(context);
        }

        void setVideoDimensions(int width, int height) {
            if (width <= 0 || height <= 0) return;
            videoWidth = width;
            videoHeight = height;
            requestLayout();
        }

        @Override protected void onMeasure(int widthMeasureSpec, int heightMeasureSpec) {
            int maxWidth = Math.max(1, View.MeasureSpec.getSize(widthMeasureSpec));
            int maxHeight = Math.max(1, View.MeasureSpec.getSize(heightMeasureSpec));
            float aspect = videoWidth / (float) Math.max(1, videoHeight);
            int width = maxWidth;
            int height = Math.max(1, Math.round(width / aspect));
            if (height > maxHeight) {
                height = maxHeight;
                width = Math.max(1, Math.round(height * aspect));
            }
            setMeasuredDimension(width, height);
        }
    }

    private interface SwipeListener { void onSwipe(int direction); }

    private static final class ZoomImageView extends androidx.appcompat.widget.AppCompatImageView {
        private float scale = 1f;
        private final ScaleGestureDetector scaleDetector;
        private final GestureDetector gestureDetector;

        ZoomImageView(Context context, SwipeListener swipeListener) {
            super(context);
            scaleDetector = new ScaleGestureDetector(context, new ScaleGestureDetector.SimpleOnScaleGestureListener() {
                @Override public boolean onScale(ScaleGestureDetector detector) {
                    scale = Math.max(1f, Math.min(5f, scale * detector.getScaleFactor()));
                    setScaleX(scale);
                    setScaleY(scale);
                    return true;
                }
            });
            gestureDetector = new GestureDetector(context, new GestureDetector.SimpleOnGestureListener() {
                @Override public boolean onDown(MotionEvent event) { return true; }
                @Override public boolean onDoubleTap(MotionEvent event) {
                    scale = scale > 1f ? 1f : 2f;
                    animate().scaleX(scale).scaleY(scale).setDuration(160L).start();
                    return true;
                }
                @Override public boolean onFling(MotionEvent down, MotionEvent up, float velocityX, float velocityY) {
                    if (scale > 1.01f || down == null || up == null
                        || Math.abs(up.getX() - down.getX()) < dp(72)
                        || Math.abs(velocityX) < Math.abs(velocityY)) return false;
                    swipeListener.onSwipe(up.getX() < down.getX() ? 1 : -1);
                    return true;
                }
            });
        }

        private int dp(int value) {
            return Math.round(value * getResources().getDisplayMetrics().density);
        }

        @Override public boolean onTouchEvent(MotionEvent event) {
            scaleDetector.onTouchEvent(event);
            gestureDetector.onTouchEvent(event);
            return true;
        }
    }
}
