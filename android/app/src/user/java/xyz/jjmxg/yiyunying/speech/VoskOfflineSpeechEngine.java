package xyz.jjmxg.yiyunying.speech;

import android.content.Context;
import android.os.Handler;
import android.os.Looper;

import com.google.gson.JsonObject;
import com.google.gson.JsonParser;

import org.vosk.Model;
import org.vosk.Recognizer;
import org.vosk.android.RecognitionListener;
import org.vosk.android.SpeechService;
import org.vosk.android.StorageService;

import java.io.IOException;

public final class VoskOfflineSpeechEngine implements RecognitionListener {
    private final Context context;
    private final OfflineSpeechTranscriber.Listener listener;
    private final Handler main = new Handler(Looper.getMainLooper());
    private final StringBuilder completed = new StringBuilder();
    private Model model;
    private SpeechService speechService;
    private boolean preparing;
    private boolean pendingStart;
    private boolean listening;

    public VoskOfflineSpeechEngine(Context context, OfflineSpeechTranscriber.Listener listener) {
        this.context = context;
        this.listener = listener;
    }

    public synchronized void prepare() {
        if (model != null) {
            main.post(listener::onReady);
            return;
        }
        if (preparing) return;
        preparing = true;
        StorageService.unpack(context, "model-cn", "vosk-model-cn",
            unpacked -> {
                synchronized (VoskOfflineSpeechEngine.this) {
                    model = unpacked;
                    preparing = false;
                }
                main.post(listener::onReady);
                boolean shouldStart;
                synchronized (VoskOfflineSpeechEngine.this) {
                    shouldStart = pendingStart;
                    pendingStart = false;
                }
                if (shouldStart) beginListening();
            }, error -> {
                synchronized (VoskOfflineSpeechEngine.this) {
                    preparing = false;
                    pendingStart = false;
                }
                main.post(() -> listener.onError("离线语音识别初始化失败"));
            });
    }

    public synchronized void start() {
        if (listening) return;
        if (model == null) {
            pendingStart = true;
            prepare();
            return;
        }
        beginListening();
    }

    private void beginListening() {
        synchronized (this) {
            if (model == null || listening) return;
            completed.setLength(0);
            try {
                Recognizer recognizer = new Recognizer(model, 16000.0f);
                speechService = new SpeechService(recognizer, 16000.0f);
                listening = true;
            } catch (IOException | RuntimeException error) {
                listening = false;
                main.post(() -> listener.onError("离线语音识别暂时不可用"));
                return;
            }
        }
        main.post(listener::onListening);
        speechService.startListening(this);
    }

    public synchronized void stop() {
        pendingStart = false;
        if (speechService != null) speechService.stop();
    }

    public synchronized void cancel() {
        pendingStart = false;
        listening = false;
        if (speechService != null) {
            speechService.cancel();
            speechService = null;
        }
    }

    public synchronized void shutdown() {
        cancel();
        if (model != null) {
            model.close();
            model = null;
        }
    }

    @Override public void onPartialResult(String hypothesis) {
        String partial = value(hypothesis, "partial");
        String aggregate = aggregate(partial);
        if (!aggregate.isEmpty()) main.post(() -> listener.onPartialResult(aggregate));
    }

    @Override public void onResult(String hypothesis) {
        append(value(hypothesis, "text"));
        String aggregate = completed.toString().trim();
        if (!aggregate.isEmpty()) main.post(() -> listener.onPartialResult(aggregate));
    }

    @Override public void onFinalResult(String hypothesis) {
        append(value(hypothesis, "text"));
        String result = completed.toString().trim();
        synchronized (this) {
            listening = false;
            speechService = null;
        }
        main.post(() -> listener.onFinalResult(result));
    }

    @Override public void onError(Exception error) {
        synchronized (this) {
            listening = false;
            speechService = null;
        }
        main.post(() -> listener.onError("离线语音识别暂时不可用"));
    }

    @Override public void onTimeout() {
        stop();
    }

    private synchronized String aggregate(String partial) {
        String prefix = completed.toString().trim();
        if (prefix.isEmpty()) return partial.trim();
        if (partial.trim().isEmpty()) return prefix;
        return prefix + " " + partial.trim();
    }

    private synchronized void append(String text) {
        String value = text == null ? "" : text.trim();
        if (value.isEmpty()) return;
        if (completed.length() > 0) completed.append(' ');
        completed.append(value);
    }

    private static String value(String json, String key) {
        try {
            JsonObject object = JsonParser.parseString(json == null ? "{}" : json).getAsJsonObject();
            return object.has(key) && !object.get(key).isJsonNull() ? object.get(key).getAsString() : "";
        } catch (RuntimeException ignored) {
            return "";
        }
    }
}
