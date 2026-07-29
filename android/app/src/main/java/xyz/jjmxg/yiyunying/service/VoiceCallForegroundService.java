package xyz.jjmxg.yiyunying.service;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.app.Service;
import android.content.Context;
import android.content.Intent;
import android.content.pm.ServiceInfo;
import android.os.Build;
import android.os.Handler;
import android.os.IBinder;
import android.os.Looper;
import android.os.PowerManager;
import android.os.SystemClock;
import android.net.wifi.WifiManager;

import androidx.annotation.Nullable;
import androidx.core.app.NotificationCompat;
import androidx.core.content.ContextCompat;

import com.google.gson.JsonObject;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.core.AppAccess;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.data.api.RequestHandle;
import xyz.jjmxg.yiyunying.domain.call.CallDurationClock;
import xyz.jjmxg.yiyunying.ui.voice.VoiceCallActivity;

public final class VoiceCallForegroundService extends Service {
    public static final String ACTION_START = "xyz.jjmxg.yiyunying.action.VOICE_CALL_START";
    public static final String ACTION_HANGUP = "xyz.jjmxg.yiyunying.action.VOICE_CALL_HANGUP";
    public static final String ACTION_STOP = "xyz.jjmxg.yiyunying.action.VOICE_CALL_STOP";
    public static final String ACTION_LOCAL_ENDED = "xyz.jjmxg.yiyunying.action.VOICE_CALL_ENDED";
    public static final String EXTRA_CALL_ID = "call_id";
    public static final String EXTRA_PEER_NAME = "peer_name";
    public static final String EXTRA_CALL_TYPE = "call_type";
    public static final String EXTRA_DURATION_SECONDS = "duration_seconds";
    private static final String CHANNEL = "active_voice_call_v3";
    private static final int FALLBACK_NOTIFICATION_ID = 13001;

    private final Handler handler = new Handler(Looper.getMainLooper());
    private long callId;
    private String peerName = "好友";
    private String callType = "audio";
    private CallDurationClock durationClock = new CallDurationClock();
    private int foregroundNotificationId = FALLBACK_NOTIFICATION_ID;
    private RequestHandle stateRequest;
    private RequestHandle hangupRequest;
    private long hangingUpCallId;
    private long hangupDurationSeconds;
    private int hangupAttempts;
    private PowerManager.WakeLock callWakeLock;
    private WifiManager.WifiLock callWifiLock;
    private final Runnable statePoller = this::pollCallState;
    private final Runnable hangupRetry = this::sendHangup;
    private final Runnable ticker = new Runnable() {
        @Override public void run() {
            if (callId <= 0) return;
            NotificationManager manager = getSystemService(NotificationManager.class);
            if (manager != null) manager.notify(foregroundNotificationId, notification());
            handler.postDelayed(this, 1000L);
        }
    };

    public static void start(Context context, long callId, String peerName, String callType,
                             long durationSeconds) {
        Intent intent = new Intent(context, VoiceCallForegroundService.class)
            .setAction(ACTION_START)
            .putExtra(EXTRA_CALL_ID, callId)
            .putExtra(EXTRA_PEER_NAME, peerName)
            .putExtra(EXTRA_CALL_TYPE, callType)
            .putExtra(EXTRA_DURATION_SECONDS, Math.max(0L, durationSeconds));
        ContextCompat.startForegroundService(context, intent);
    }

    public static void stop(Context context) {
        NotificationManager manager = (NotificationManager) context.getSystemService(Context.NOTIFICATION_SERVICE);
        if (manager != null) manager.cancel(FALLBACK_NOTIFICATION_ID);
        context.stopService(new Intent(context, VoiceCallForegroundService.class));
    }

    public static void requestHangup(Context context, long callId) {
        requestHangup(context, callId, 0L);
    }

    public static void requestHangup(Context context, long callId, long durationSeconds) {
        if (callId <= 0) return;
        Intent intent = new Intent(context, VoiceCallForegroundService.class)
            .setAction(ACTION_HANGUP)
            .putExtra(EXTRA_CALL_ID, callId)
            .putExtra(EXTRA_DURATION_SECONDS, Math.max(0L, durationSeconds));
        context.startService(intent);
    }

    public static PendingIntent hangupPendingIntent(Context context, long callId, int requestCode) {
        Intent intent = new Intent(context, VoiceCallForegroundService.class)
            .setAction(ACTION_HANGUP)
            .putExtra(EXTRA_CALL_ID, callId);
        return PendingIntent.getService(context, requestCode, intent,
            PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);
    }

    @Override public void onCreate() {
        super.onCreate();
        createChannel();
    }

    @Override public int onStartCommand(Intent intent, int flags, int startId) {
        String action = intent == null ? "" : intent.getAction();
        long requestedCallId = intent == null ? 0 : intent.getLongExtra(EXTRA_CALL_ID, 0);
        if (ACTION_HANGUP.equals(action)) {
            hangup(requestedCallId,
                intent == null ? 0L : intent.getLongExtra(EXTRA_DURATION_SECONDS, 0L));
            return START_NOT_STICKY;
        }
        if (ACTION_STOP.equals(action)) {
            endNow(requestedCallId, false);
            return START_NOT_STICKY;
        }
        if (intent == null || requestedCallId <= 0) {
            endNow(0, false);
            return START_NOT_STICKY;
        }
        boolean sameCall = callId == requestedCallId && durationClock.isRunning();
        callId = requestedCallId;
        peerName = intent == null ? "好友" : intent.getStringExtra(EXTRA_PEER_NAME);
        if (peerName == null || peerName.trim().isEmpty()) peerName = "好友";
        callType = intent != null && "video".equals(intent.getStringExtra(EXTRA_CALL_TYPE)) ? "video" : "audio";
        long suppliedDuration = intent.getLongExtra(EXTRA_DURATION_SECONDS, 0L);
        if (!sameCall) durationClock = new CallDurationClock();
        foregroundNotificationId = MessageNotificationService.callNotificationId(callId);
        durationClock.sync(suppliedDuration, true, SystemClock.elapsedRealtime());
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            int foregroundTypes = ServiceInfo.FOREGROUND_SERVICE_TYPE_MICROPHONE;
            if ("video".equals(callType)) foregroundTypes |= ServiceInfo.FOREGROUND_SERVICE_TYPE_CAMERA;
            startForeground(foregroundNotificationId, notification(), foregroundTypes);
        } else {
            startForeground(foregroundNotificationId, notification());
        }
        acquireCallLocks();
        handler.removeCallbacks(ticker);
        handler.postDelayed(ticker, 1000L);
        handler.removeCallbacks(statePoller);
        handler.post(statePoller);
        // A stale system restart must never resurrect a finished call notification.
        return START_NOT_STICKY;
    }

    private void pollCallState() {
        handler.removeCallbacks(statePoller);
        long requestedCallId = callId;
        if (requestedCallId <= 0 || stateRequest != null) return;
        stateRequest = AppAccess.from(this).repository().get(
            "/api/user/voice-calls/" + requestedCallId,
            new java.util.LinkedHashMap<>(), result -> {
                stateRequest = null;
                if (callId != requestedCallId || callId <= 0) return;
                if (result.isAuthenticationFailure()) {
                    endNow(requestedCallId, true);
                    return;
                }
                if (result.httpCode() == 404 || result.httpCode() == 410) {
                    endNow(requestedCallId, true);
                    return;
                }
                if (result.isSuccessful()) {
                    JsonObject call = Jsons.object(result.dataObject(), "call");
                    String status = Jsons.string(call, "status");
                    if ("active".equals(status)) {
                        durationClock.sync(Jsons.longValue(call, "current_duration_seconds"), true,
                            SystemClock.elapsedRealtime());
                    }
                    boolean terminal = "declined".equals(status) || "cancelled".equals(status)
                        || "missed".equals(status) || "ended".equals(status);
                    try {
                        terminal = terminal || (call.has("is_terminal") && call.get("is_terminal").getAsBoolean());
                    } catch (RuntimeException ignored) { }
                    if (terminal) {
                        endNow(requestedCallId, true);
                        return;
                    }
                }
                handler.postDelayed(statePoller, 750L);
            });
    }

    private void hangup(long requestedCallId, long suppliedDurationSeconds) {
        long id = requestedCallId > 0 ? requestedCallId : callId;
        if (id <= 0) { endNow(0, false); return; }
        if (hangingUpCallId == id && hangupRequest != null) return;
        hangingUpCallId = id;
        callId = id;
        hangupDurationSeconds = Math.max(Math.max(0L, suppliedDurationSeconds),
            durationClock.seconds(SystemClock.elapsedRealtime()));
        hangupAttempts = 0;
        handler.removeCallbacks(ticker);
        handler.removeCallbacks(statePoller);
        if (stateRequest != null) {
            stateRequest.cancel();
            stateRequest = null;
        }
        dismissForegroundNotification();
        sendHangup();
    }

    private void sendHangup() {
        handler.removeCallbacks(hangupRetry);
        long id = hangingUpCallId;
        if (id <= 0 || hangupRequest != null) return;
        hangupAttempts++;
        JsonObject body = new JsonObject();
        body.addProperty("duration_seconds", hangupDurationSeconds);
        hangupRequest = AppAccess.from(this).repository().post(
            "/api/user/voice-calls/" + id + "/hangup", body, result -> {
                hangupRequest = null;
                boolean delivered = result.isSuccessful() || result.httpCode() == 404
                    || result.httpCode() == 409 || result.httpCode() == 410;
                if (!delivered && hangupAttempts < 4) {
                    handler.postDelayed(hangupRetry, 250L * hangupAttempts);
                    return;
                }
                endNow(id, true);
            });
    }

    private void endNow(long id, boolean broadcast) {
        handler.removeCallbacks(ticker);
        handler.removeCallbacks(statePoller);
        handler.removeCallbacks(hangupRetry);
        if (stateRequest != null) {
            stateRequest.cancel();
            stateRequest = null;
        }
        if (hangupRequest != null) {
            hangupRequest.cancel();
            hangupRequest = null;
        }
        if (broadcast && id > 0) {
            Intent ended = new Intent(ACTION_LOCAL_ENDED).setPackage(getPackageName()).putExtra(EXTRA_CALL_ID, id);
            sendBroadcast(ended);
        }
        dismissForegroundNotification();
        releaseCallLocks();
        callId = 0;
        foregroundNotificationId = FALLBACK_NOTIFICATION_ID;
        hangingUpCallId = 0;
        hangupDurationSeconds = 0L;
        stopSelf();
    }

    private void dismissForegroundNotification() {
        NotificationManager manager = getSystemService(NotificationManager.class);
        if (manager != null) {
            manager.cancel(foregroundNotificationId);
            manager.cancel(FALLBACK_NOTIFICATION_ID);
        }
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.N) stopForeground(STOP_FOREGROUND_REMOVE);
        else {
            //noinspection deprecation
            stopForeground(true);
        }
    }

    private void acquireCallLocks() {
        try {
            if (callWakeLock == null) {
                PowerManager manager = (PowerManager) getSystemService(POWER_SERVICE);
                if (manager != null) {
                    callWakeLock = manager.newWakeLock(PowerManager.PARTIAL_WAKE_LOCK,
                        getPackageName() + ":voice-call");
                    callWakeLock.setReferenceCounted(false);
                }
            }
            if (callWakeLock != null && !callWakeLock.isHeld()) {
                callWakeLock.acquire(2 * 60 * 60 * 1000L);
            }
        } catch (RuntimeException ignored) { }
        try {
            if (callWifiLock == null) {
                WifiManager manager = (WifiManager) getApplicationContext().getSystemService(WIFI_SERVICE);
                if (manager != null) {
                    callWifiLock = manager.createWifiLock(WifiManager.WIFI_MODE_FULL_HIGH_PERF,
                        getPackageName() + ":voice-call");
                    callWifiLock.setReferenceCounted(false);
                }
            }
            if (callWifiLock != null && !callWifiLock.isHeld()) callWifiLock.acquire();
        } catch (RuntimeException ignored) { }
    }

    private void releaseCallLocks() {
        try {
            if (callWakeLock != null && callWakeLock.isHeld()) callWakeLock.release();
        } catch (RuntimeException ignored) { }
        try {
            if (callWifiLock != null && callWifiLock.isHeld()) callWifiLock.release();
        } catch (RuntimeException ignored) { }
        callWakeLock = null;
        callWifiLock = null;
    }

    private Notification notification() {
        long seconds = durationClock.seconds(SystemClock.elapsedRealtime());
        String time = String.format(java.util.Locale.CHINA, "%02d:%02d", seconds / 60L, seconds % 60L);
        boolean video = "video".equals(callType);
        Intent open = VoiceCallActivity.resumeIntent(this, callId, peerName, callType);
        PendingIntent content = PendingIntent.getActivity(this, 13002, open,
            PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);
        return new NotificationCompat.Builder(this, CHANNEL)
            .setSmallIcon(video ? R.drawable.ic_videocam : R.drawable.ic_phone)
            .setContentTitle("正在与 " + peerName + (video ? " 视频通话" : " 语音通话"))
            .setContentText("已通话 " + time + (video ? " · 可返回桌面使用画中画" : " · 扬声器默认关闭"))
            .setContentIntent(content)
            .addAction(R.drawable.ic_call_end, "挂断", hangupPendingIntent(this, callId, 13003))
            .setCategory(NotificationCompat.CATEGORY_CALL)
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setOngoing(true)
            .setOnlyAlertOnce(true)
            .setSilent(true)
            .build();
    }

    private void createChannel() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return;
        NotificationManager manager = getSystemService(NotificationManager.class);
        if (manager == null) return;
        NotificationChannel channel = new NotificationChannel(CHANNEL, "进行中的通话", NotificationManager.IMPORTANCE_LOW);
        channel.setDescription("显示语音或视频通话状态和挂断操作");
        channel.setSound(null, null);
        manager.createNotificationChannel(channel);
    }

    @Override public void onDestroy() {
        handler.removeCallbacks(ticker);
        handler.removeCallbacks(statePoller);
        handler.removeCallbacks(hangupRetry);
        if (stateRequest != null) {
            stateRequest.cancel();
            stateRequest = null;
        }
        if (hangupRequest != null) {
            hangupRequest.cancel();
            hangupRequest = null;
        }
        NotificationManager manager = getSystemService(NotificationManager.class);
        if (manager != null) {
            manager.cancel(foregroundNotificationId);
            manager.cancel(FALLBACK_NOTIFICATION_ID);
        }
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.N) stopForeground(STOP_FOREGROUND_REMOVE);
        else {
            //noinspection deprecation
            stopForeground(true);
        }
        releaseCallLocks();
        super.onDestroy();
    }

    @Nullable @Override public IBinder onBind(Intent intent) { return null; }
}
