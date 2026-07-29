package xyz.jjmxg.yiyunying.domain.call;

/** Keeps a call duration monotonic while periodically reconciling server state. */
public final class CallDurationClock {
    private long baseSeconds;
    private long baseElapsedMillis;

    public void sync(long serverSeconds, boolean active, long elapsedRealtimeMillis) {
        long safeServerSeconds = Math.max(0L, serverSeconds);
        if (!active) {
            // Polling responses can arrive out of order. Once media has started,
            // a delayed "ringing" response must not reset the caller's clock.
            if (baseElapsedMillis <= 0L) baseSeconds = Math.max(baseSeconds, safeServerSeconds);
            return;
        }
        long now = Math.max(0L, elapsedRealtimeMillis);
        if (baseElapsedMillis <= 0L) {
            baseSeconds = safeServerSeconds;
            baseElapsedMillis = now;
            return;
        }
        long localSeconds = seconds(now);
        // A delayed server response may be behind the local clock. Only move forward.
        if (safeServerSeconds > localSeconds + 1L) {
            baseSeconds = safeServerSeconds;
            baseElapsedMillis = now;
        }
    }

    public long seconds(long elapsedRealtimeMillis) {
        if (baseElapsedMillis <= 0L) return Math.max(0L, baseSeconds);
        long elapsed = Math.max(0L, elapsedRealtimeMillis - baseElapsedMillis);
        return Math.max(0L, baseSeconds + elapsed / 1000L);
    }

    public boolean isRunning() {
        return baseElapsedMillis > 0L;
    }

    public void stop(long serverSeconds, long elapsedRealtimeMillis) {
        baseSeconds = Math.max(Math.max(0L, serverSeconds), seconds(elapsedRealtimeMillis));
        baseElapsedMillis = 0L;
    }

    public void reset() {
        baseSeconds = 0L;
        baseElapsedMillis = 0L;
    }
}
