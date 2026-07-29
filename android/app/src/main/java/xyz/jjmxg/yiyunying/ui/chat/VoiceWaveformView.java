package xyz.jjmxg.yiyunying.ui.chat;

import android.content.Context;
import android.graphics.Canvas;
import android.graphics.Paint;
import android.util.AttributeSet;
import android.view.MotionEvent;
import android.view.View;

import androidx.annotation.Nullable;

import xyz.jjmxg.yiyunying.R;

/** Compact waveform shared by voice playback and live recording. */
public final class VoiceWaveformView extends View {
    interface OnSeekListener {
        void onSeek(int positionMs);
    }

    private final Paint playedPaint = new Paint(Paint.ANTI_ALIAS_FLAG);
    private final Paint remainingPaint = new Paint(Paint.ANTI_ALIAS_FLAG);
    private int maximum = 1;
    private int progress;
    private OnSeekListener listener;
    private final float[] liveSamples = new float[44];
    private int liveSampleCount;
    private boolean recordingMode;

    public VoiceWaveformView(Context context) {
        this(context, null);
    }

    public VoiceWaveformView(Context context, @Nullable AttributeSet attrs) {
        super(context, attrs);
        playedPaint.setColor(xyz.jjmxg.yiyunying.ui.common.ThemeColors.primary(context));
        remainingPaint.setColor(context.getColor(R.color.outline));
        playedPaint.setStrokeCap(Paint.Cap.ROUND);
        remainingPaint.setStrokeCap(Paint.Cap.ROUND);
        setMinimumHeight(dp(38));
        setContentDescription("语音播放进度");
    }

    void setMaximum(int maximum) {
        this.maximum = Math.max(1, maximum);
        progress = Math.min(progress, this.maximum);
        invalidate();
    }

    int getMaximum() {
        return maximum;
    }

    void setProgressValue(int progress) {
        this.progress = Math.max(0, Math.min(maximum, progress));
        invalidate();
    }

    void setOnSeekListener(OnSeekListener listener) {
        this.listener = listener;
    }

    public void setRecordingMode(boolean recordingMode) {
        this.recordingMode = recordingMode;
        if (!recordingMode) liveSampleCount = 0;
        setContentDescription(recordingMode ? "实时录音波形" : "语音播放进度");
        invalidate();
    }

    public void pushAmplitude(float amplitude) {
        float value = Math.max(0.08f, Math.min(1f, amplitude));
        if (liveSampleCount < liveSamples.length) {
            liveSamples[liveSampleCount++] = value;
        } else {
            System.arraycopy(liveSamples, 1, liveSamples, 0, liveSamples.length - 1);
            liveSamples[liveSamples.length - 1] = value;
        }
        invalidate();
    }

    @Override protected void onDraw(Canvas canvas) {
        super.onDraw(canvas);
        int width = getWidth() - getPaddingLeft() - getPaddingRight();
        int height = getHeight() - getPaddingTop() - getPaddingBottom();
        if (width <= 0 || height <= 0) return;
        int bars = Math.max(18, Math.min(44, width / dp(5)));
        float slot = width / (float) bars;
        float stroke = Math.max(dp(2), Math.min(dp(3), slot * 0.48f));
        playedPaint.setStrokeWidth(stroke);
        remainingPaint.setStrokeWidth(stroke);
        float centerY = getPaddingTop() + height / 2f;
        float fraction = progress / (float) maximum;
        for (int i = 0; i < bars; i++) {
            float wave;
            if (recordingMode) {
                int offset = Math.max(0, liveSampleCount - bars);
                int sampleIndex = offset + i;
                wave = sampleIndex < liveSampleCount ? liveSamples[sampleIndex] : 0.08f;
            } else {
                // Stable message waveform keeps scrolling cheap while still
                // making a recorded voice visually distinct from audio files.
                wave = 0.28f + (((i * 37 + 11) % 17) / 16f) * 0.68f;
                if (i % 5 == 0) wave *= 0.72f;
            }
            float half = Math.max(dp(3), height * wave * 0.43f);
            float x = getPaddingLeft() + slot * (i + 0.5f);
            canvas.drawLine(x, centerY - half, x, centerY + half,
                recordingMode || (i + 0.5f) / bars <= fraction ? playedPaint : remainingPaint);
        }
    }

    @Override public boolean onTouchEvent(MotionEvent event) {
        if (recordingMode) return false;
        if (event.getActionMasked() == MotionEvent.ACTION_DOWN
            || event.getActionMasked() == MotionEvent.ACTION_MOVE
            || event.getActionMasked() == MotionEvent.ACTION_UP) {
            float usable = Math.max(1f, getWidth() - getPaddingLeft() - getPaddingRight());
            float fraction = Math.max(0f, Math.min(1f, (event.getX() - getPaddingLeft()) / usable));
            int value = Math.round(maximum * fraction);
            setProgressValue(value);
            if (listener != null) listener.onSeek(value);
            if (event.getActionMasked() == MotionEvent.ACTION_UP) performClick();
            return true;
        }
        return super.onTouchEvent(event);
    }

    @Override public boolean performClick() {
        super.performClick();
        return true;
    }

    private int dp(int value) {
        return Math.round(value * getResources().getDisplayMetrics().density);
    }
}
