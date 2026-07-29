package xyz.jjmxg.yiyunying.data.api;

import java.util.concurrent.atomic.AtomicBoolean;
import java.util.concurrent.atomic.AtomicReference;

import okhttp3.Call;

public final class RequestHandle {
    private final AtomicBoolean cancelled = new AtomicBoolean(false);
    private final AtomicReference<Call> call = new AtomicReference<>();

    void attach(Call next) {
        Call previous = call.getAndSet(next);
        if (previous != null && previous != next) {
            previous.cancel();
        }
        if (cancelled.get()) {
            next.cancel();
        }
    }

    public void cancel() {
        cancelled.set(true);
        Call current = call.get();
        if (current != null) {
            current.cancel();
        }
    }

    public boolean isCancelled() {
        return cancelled.get();
    }
}
