package xyz.jjmxg.yiyunying.speech;

import android.content.Context;

import java.lang.reflect.Constructor;
import java.lang.reflect.Method;

public final class OfflineSpeechTranscriber {
    public interface Listener {
        void onReady();
        void onListening();
        void onPartialResult(String text);
        void onFinalResult(String text);
        void onError(String message);
    }

    private static final String IMPLEMENTATION =
        "xyz.jjmxg.yiyunying.speech.VoskOfflineSpeechEngine";

    private final Object delegate;
    private final Method prepare;
    private final Method start;
    private final Method stop;
    private final Method cancel;
    private final Method shutdown;

    private OfflineSpeechTranscriber(Object delegate, Class<?> type) throws ReflectiveOperationException {
        this.delegate = delegate;
        prepare = type.getMethod("prepare");
        start = type.getMethod("start");
        stop = type.getMethod("stop");
        cancel = type.getMethod("cancel");
        shutdown = type.getMethod("shutdown");
    }

    public static OfflineSpeechTranscriber create(Context context, Listener listener) {
        try {
            Class<?> type = Class.forName(IMPLEMENTATION);
            Constructor<?> constructor = type.getConstructor(Context.class, Listener.class);
            Object delegate = constructor.newInstance(context.getApplicationContext(), listener);
            return new OfflineSpeechTranscriber(delegate, type);
        } catch (ReflectiveOperationException | LinkageError ignored) {
            return null;
        }
    }

    public void prepare() { invoke(prepare); }
    public void start() { invoke(start); }
    public void stop() { invoke(stop); }
    public void cancel() { invoke(cancel); }
    public void shutdown() { invoke(shutdown); }

    private void invoke(Method method) {
        try {
            method.invoke(delegate);
        } catch (ReflectiveOperationException | RuntimeException ignored) { }
    }
}
