package xyz.jjmxg.yiyunying.ui.chat;

import android.content.Context;
import android.content.res.ColorStateList;
import android.graphics.drawable.GradientDrawable;
import android.media.MediaPlayer;
import android.media.PlaybackParams;
import android.os.Build;
import android.os.Handler;
import android.os.Looper;
import android.view.Gravity;
import android.widget.LinearLayout;
import android.widget.SeekBar;
import android.widget.TextView;

import com.google.android.material.button.MaterialButton;

import java.io.IOException;
import java.util.Locale;

import xyz.jjmxg.yiyunying.R;

public final class InlineAudioPlayerView extends LinearLayout {
    private final MaterialButton play;
    private final MaterialButton speedButton;
    private final SeekBar audioProgress;
    private final VoiceWaveformView voiceProgress;
    private final TextView time;
    private MaterialButton rewindButton;
    private MaterialButton forwardButton;
    private final Handler handler = new Handler(Looper.getMainLooper());
    private final String source;
    private MediaPlayer player;
    private boolean preparing;
    private float playbackSpeed = 1f;

    /** Generic audio file player: conventional horizontal seek bar. */
    public InlineAudioPlayerView(Context context, String source, long knownDurationMs) {
        this(context, source, knownDurationMs, false);
    }

    /** @param voiceMode true for an in-chat recorded voice, false for an audio file. */
    public InlineAudioPlayerView(Context context, String source, long knownDurationMs, boolean voiceMode) {
        super(context);
        this.source = source;
        setOrientation(HORIZONTAL);
        setGravity(Gravity.CENTER_VERTICAL);
        setPadding(dp(6), dp(5), dp(7), dp(5));
        GradientDrawable background = new GradientDrawable();
        background.setColor(context.getColor(R.color.surface_container_high));
        background.setCornerRadius(dp(10));
        background.setStroke(dp(1), context.getColor(R.color.outline));
        setBackground(background);

        speedButton = new MaterialButton(context, null, com.google.android.material.R.attr.materialButtonOutlinedStyle);
        speedButton.setTextSize(10);
        speedButton.setMinWidth(0);
        speedButton.setMinimumWidth(0);
        speedButton.setMinHeight(0);
        speedButton.setMinimumHeight(0);
        speedButton.setInsetTop(0);
        speedButton.setInsetBottom(0);
        speedButton.setPadding(0, 0, 0, 0);
        speedButton.setMaxLines(1);
        speedButton.setAllCaps(false);
        renderSpeedButton();
        speedButton.setOnClickListener(view -> cycleSpeed());
        addView(speedButton, new LayoutParams(dp(44), dp(34)));

        if (!voiceMode) {
            rewindButton = seekButton("-10");
            rewindButton.setContentDescription("后退 10 秒");
            rewindButton.setOnClickListener(view -> seekBy(-10_000));
            addView(rewindButton, new LayoutParams(dp(36), dp(34)));
        }

        play = new MaterialButton(context, null, com.google.android.material.R.attr.materialIconButtonStyle);
        play.setIconResource(R.drawable.ic_play);
        play.setIconTint(ColorStateList.valueOf(context.getColor(R.color.on_surface)));
        play.setContentDescription(voiceMode ? "播放语音" : "播放音频");
        addView(play, new LayoutParams(dp(42), dp(42)));

        if (!voiceMode) {
            forwardButton = seekButton("+10");
            forwardButton.setContentDescription("前进 10 秒");
            forwardButton.setOnClickListener(view -> seekBy(10_000));
            addView(forwardButton, new LayoutParams(dp(36), dp(34)));
        }

        if (voiceMode) {
            voiceProgress = new VoiceWaveformView(context);
            voiceProgress.setMaximum(durationValue(knownDurationMs));
            voiceProgress.setOnSeekListener(this::seekTo);
            audioProgress = null;
            addView(voiceProgress, new LayoutParams(0, dp(42), 1f));
        } else {
            audioProgress = new SeekBar(context);
            audioProgress.setMax(durationValue(knownDurationMs));
            audioProgress.setProgressTintList(ColorStateList.valueOf(xyz.jjmxg.yiyunying.ui.common.ThemeColors.primary(context)));
            audioProgress.setThumbTintList(ColorStateList.valueOf(xyz.jjmxg.yiyunying.ui.common.ThemeColors.primary(context)));
            audioProgress.setOnSeekBarChangeListener(new SeekBar.OnSeekBarChangeListener() {
                @Override public void onProgressChanged(SeekBar seekBar, int value, boolean fromUser) {
                    if (fromUser) seekTo(value);
                    updateTime(value);
                }
                @Override public void onStartTrackingTouch(SeekBar seekBar) { }
                @Override public void onStopTrackingTouch(SeekBar seekBar) { }
            });
            voiceProgress = null;
            addView(audioProgress, new LayoutParams(0, dp(42), 1f));
        }

        time = new TextView(context);
        time.setTextSize(11);
        time.setTextColor(context.getColor(R.color.on_surface_variant));
        time.setGravity(Gravity.CENTER);
        time.setSingleLine(true);
        time.setText(format(0) + " / " + format(knownDurationMs));
        addView(time, new LayoutParams(dp(78), dp(42)));

        play.setOnClickListener(view -> toggle());
    }

    private void toggle() {
        if (player == null) {
            prepareAndPlay();
            return;
        }
        if (player.isPlaying()) {
            player.pause();
            play.setIconResource(R.drawable.ic_play);
            handler.removeCallbacks(tick);
        } else {
            player.start();
            play.setIconResource(R.drawable.ic_pause);
            handler.post(tick);
        }
    }

    private void prepareAndPlay() {
        if (preparing || source == null || source.isEmpty()) return;
        preparing = true;
        play.setEnabled(false);
        player = new MediaPlayer();
        try {
            player.setDataSource(source);
            player.setOnPreparedListener(value -> {
                preparing = false;
                play.setEnabled(true);
                setMaximum(value.getDuration());
                applyPlaybackSpeed();
                value.start();
                play.setIconResource(R.drawable.ic_pause);
                handler.post(tick);
            });
            player.setOnCompletionListener(value -> {
                setProgressValue(0);
                updateTime(0);
                play.setIconResource(R.drawable.ic_play);
                handler.removeCallbacks(tick);
            });
            player.setOnErrorListener((value, what, extra) -> {
                preparing = false;
                play.setEnabled(true);
                play.setIconResource(R.drawable.ic_play);
                return true;
            });
            player.prepareAsync();
        } catch (IOException | RuntimeException exception) {
            preparing = false;
            play.setEnabled(true);
            releasePlayer();
        }
    }

    private void seekTo(int value) {
        if (player != null) {
            try { player.seekTo(value); } catch (RuntimeException ignored) { }
        }
        updateTime(value);
    }

    private MaterialButton seekButton(String label) {
        MaterialButton button = new MaterialButton(getContext(), null,
            com.google.android.material.R.attr.materialButtonOutlinedStyle);
        button.setText(label);
        button.setTextSize(9);
        button.setAllCaps(false);
        button.setMinWidth(0);
        button.setMinimumWidth(0);
        button.setMinHeight(0);
        button.setMinimumHeight(0);
        button.setInsetTop(0);
        button.setInsetBottom(0);
        button.setPadding(0, 0, 0, 0);
        button.setTextColor(getContext().getColor(R.color.on_surface));
        button.setStrokeColor(ColorStateList.valueOf(getContext().getColor(R.color.outline)));
        return button;
    }

    private void seekBy(int deltaMs) {
        if (player == null) return;
        try {
            int target = Math.max(0, Math.min(player.getDuration(), player.getCurrentPosition() + deltaMs));
            player.seekTo(target);
            setProgressValue(target);
            updateTime(target);
        } catch (RuntimeException ignored) { }
    }

    private void cycleSpeed() {
        if (playbackSpeed < 1.5f) playbackSpeed = 1.5f;
        else if (playbackSpeed < 2f) playbackSpeed = 2f;
        else if (playbackSpeed < 3f) playbackSpeed = 3f;
        else playbackSpeed = 1f;
        renderSpeedButton();
        applyPlaybackSpeed();
    }

    private void renderSpeedButton() {
        String label = playbackSpeed == (int) playbackSpeed
            ? ((int) playbackSpeed) + "×" : String.format(Locale.CHINA, "%.1f×", playbackSpeed);
        speedButton.setText(label);
        speedButton.setTextColor(getContext().getColor(R.color.on_primary));
        speedButton.setStrokeColor(ColorStateList.valueOf(xyz.jjmxg.yiyunying.ui.common.ThemeColors.primary(getContext())));
        speedButton.setBackgroundTintList(ColorStateList.valueOf(xyz.jjmxg.yiyunying.ui.common.ThemeColors.primary(getContext())));
        speedButton.setContentDescription("当前" + label + "，点击调整播放倍速");
    }

    private void applyPlaybackSpeed() {
        if (player == null || Build.VERSION.SDK_INT < Build.VERSION_CODES.M) return;
        try {
            PlaybackParams parameters = player.getPlaybackParams();
            parameters.setSpeed(playbackSpeed);
            player.setPlaybackParams(parameters);
        } catch (RuntimeException ignored) { }
    }

    private final Runnable tick = new Runnable() {
        @Override public void run() {
            if (player == null) return;
            try {
                int current = player.getCurrentPosition();
                setProgressValue(current);
                updateTime(current);
                if (player.isPlaying()) handler.postDelayed(this, 120L);
            } catch (RuntimeException ignored) { }
        }
    };

    private void setMaximum(int maximum) {
        if (voiceProgress != null) voiceProgress.setMaximum(Math.max(1, maximum));
        if (audioProgress != null) audioProgress.setMax(Math.max(1, maximum));
    }

    private int maximum() {
        return voiceProgress != null ? voiceProgress.getMaximum() : audioProgress.getMax();
    }

    private void setProgressValue(int value) {
        if (voiceProgress != null) voiceProgress.setProgressValue(value);
        if (audioProgress != null) audioProgress.setProgress(value);
    }

    private void updateTime(long current) {
        long duration = player == null ? maximum() : player.getDuration();
        time.setText(format(current) + " / " + format(duration));
    }

    private int durationValue(long millis) {
        return (int) Math.max(1, Math.min(Integer.MAX_VALUE, millis));
    }

    private String format(long millis) {
        long seconds = Math.max(0, millis / 1000L);
        return String.format(Locale.CHINA, "%02d:%02d", seconds / 60L, seconds % 60L);
    }

    private int dp(int value) {
        return Math.round(value * getResources().getDisplayMetrics().density);
    }

    private void releasePlayer() {
        handler.removeCallbacks(tick);
        if (player != null) {
            try { player.stop(); } catch (RuntimeException ignored) { }
            try { player.release(); } catch (RuntimeException ignored) { }
            player = null;
        }
    }

    @Override protected void onDetachedFromWindow() {
        releasePlayer();
        super.onDetachedFromWindow();
    }
}
