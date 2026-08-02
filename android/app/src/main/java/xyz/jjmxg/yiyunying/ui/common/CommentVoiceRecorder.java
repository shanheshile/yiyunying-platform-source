package xyz.jjmxg.yiyunying.ui.common;

import android.content.Context;
import android.media.MediaRecorder;
import android.net.Uri;
import android.os.Handler;
import android.os.Looper;
import android.os.SystemClock;

import androidx.annotation.Nullable;

import java.io.File;
import java.io.IOException;
import java.util.ArrayList;
import java.util.Collections;
import java.util.List;

/** Small, lifecycle-safe recorder shared by dynamic and forum comment composers. */
public final class CommentVoiceRecorder {
    public interface Listener {
        void onTick(long elapsedMs);
        void onLimitReached();
    }

    public static final class Result {
        public final File file;
        public final Uri uri;
        public final long durationMs;
        public final long sizeBytes;
        public final List<Integer> waveform;

        private Result(File file, long durationMs, List<Integer> waveform) {
            this.file = file;
            this.uri = Uri.fromFile(file);
            this.durationMs = durationMs;
            this.sizeBytes = file.length();
            this.waveform = Collections.unmodifiableList(new ArrayList<>(waveform));
        }

        public void delete() {
            if (file.exists()) file.delete();
        }
    }

    private static final long MIN_DURATION_MS = 650L;
    private static final long MAX_DURATION_MS = 60_000L;
    private final Context context;
    private final Handler handler = new Handler(Looper.getMainLooper());
    private final List<Integer> waveform = new ArrayList<>();
    private MediaRecorder recorder;
    private File output;
    private long startedAt;
    private Listener listener;
    private boolean limitDispatched;

    public CommentVoiceRecorder(Context context) {
        this.context = context.getApplicationContext();
    }

    @SuppressWarnings("deprecation")
    public void start(Listener listener) throws IOException {
        cancel();
        this.listener = listener;
        output = new File(context.getCacheDir(), "comment_voice_" + System.currentTimeMillis() + ".m4a");
        MediaRecorder value = new MediaRecorder();
        value.setAudioSource(MediaRecorder.AudioSource.MIC);
        value.setOutputFormat(MediaRecorder.OutputFormat.MPEG_4);
        value.setAudioEncoder(MediaRecorder.AudioEncoder.AAC);
        value.setAudioEncodingBitRate(96_000);
        value.setAudioSamplingRate(44_100);
        value.setOutputFile(output.getAbsolutePath());
        try {
            value.prepare();
            value.start();
        } catch (IOException | RuntimeException exception) {
            try { value.release(); } catch (RuntimeException ignored) { }
            if (output.exists()) output.delete();
            output = null;
            throw exception;
        }
        recorder = value;
        waveform.clear();
        startedAt = SystemClock.elapsedRealtime();
        limitDispatched = false;
        handler.post(sample);
    }

    public boolean isRecording() {
        return recorder != null;
    }

    public long elapsedMs() {
        return isRecording() ? Math.max(0L, SystemClock.elapsedRealtime() - startedAt) : 0L;
    }

    @Nullable
    public Result stop() {
        MediaRecorder value = recorder;
        File file = output;
        long duration = elapsedMs();
        recorder = null;
        output = null;
        listener = null;
        handler.removeCallbacks(sample);
        if (value == null || file == null) return null;
        boolean valid = true;
        try { value.stop(); }
        catch (RuntimeException exception) { valid = false; }
        try { value.release(); } catch (RuntimeException ignored) { }
        if (!valid || duration < MIN_DURATION_MS || !file.exists() || file.length() <= 0L) {
            if (file.exists()) file.delete();
            return null;
        }
        return new Result(file, Math.min(duration, MAX_DURATION_MS), waveform);
    }

    public void cancel() {
        MediaRecorder value = recorder;
        File file = output;
        recorder = null;
        output = null;
        listener = null;
        handler.removeCallbacks(sample);
        if (value != null) {
            try { value.stop(); } catch (RuntimeException ignored) { }
            try { value.release(); } catch (RuntimeException ignored) { }
        }
        if (file != null && file.exists()) file.delete();
        waveform.clear();
    }

    public void release() {
        cancel();
    }

    private final Runnable sample = new Runnable() {
        @Override public void run() {
            MediaRecorder value = recorder;
            if (value == null) return;
            try {
                int amplitude = Math.max(0, value.getMaxAmplitude());
                int normalized = (int) Math.round(Math.min(100d,
                    Math.sqrt(amplitude / 32767d) * 100d));
                waveform.add(normalized);
                if (waveform.size() > 180) waveform.remove(0);
            } catch (RuntimeException ignored) { }
            Listener callback = listener;
            long elapsed = elapsedMs();
            if (callback != null) callback.onTick(elapsed);
            if (elapsed >= MAX_DURATION_MS && !limitDispatched) {
                limitDispatched = true;
                if (callback != null) callback.onLimitReached();
                return;
            }
            handler.postDelayed(this, 180L);
        }
    };
}
