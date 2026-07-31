package xyz.jjmxg.yiyunying.service;

import android.app.AlarmManager;
import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.app.Service;
import android.content.Context;
import android.content.Intent;
import android.media.AudioAttributes;
import android.media.RingtoneManager;
import android.os.Build;
import android.os.Bundle;
import android.os.Handler;
import android.os.IBinder;
import android.os.Looper;
import android.os.PowerManager;

import androidx.annotation.Nullable;
import androidx.annotation.RequiresApi;
import androidx.core.app.NotificationCompat;
import androidx.core.app.Person;
import androidx.core.app.RemoteInput;
import androidx.core.content.ContextCompat;

import com.google.gson.JsonArray;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;

import java.util.HashMap;
import java.util.HashSet;
import java.util.LinkedHashMap;
import java.text.SimpleDateFormat;
import java.util.Date;
import java.util.Locale;
import java.util.Map;
import java.util.Set;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.core.AppAccess;
import xyz.jjmxg.yiyunying.core.NotificationIconResolver;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.data.api.RequestHandle;
import xyz.jjmxg.yiyunying.domain.Role;
import xyz.jjmxg.yiyunying.ui.chat.ChatActivity;
import xyz.jjmxg.yiyunying.ui.forum.ForumPostActivity;
import xyz.jjmxg.yiyunying.ui.main.MainActivity;
import xyz.jjmxg.yiyunying.ui.voice.VoiceCallActivity;

public final class MessageNotificationService extends Service {
    public static final String ACTION_MESSAGES_CHANGED = "xyz.jjmxg.yiyunying.action.MESSAGES_CHANGED";
    public static final String EXTRA_CHANGED_TYPE = "changed_type";
    public static final String EXTRA_CHANGED_TARGET_ID = "changed_target_id";
    private static final String SERVICE_CHANNEL = "message_service";
    private static final String MESSAGE_CHANNEL = "incoming_messages_v4";
    private static final String MENTION_CHANNEL = "incoming_mentions_v2";
    private static final String CALL_CHANNEL = "incoming_voice_calls_v4";
    private static final String SOCIAL_CHANNEL = "social_notifications_v1";
    private static final String ACTIVITY_CHANNEL = "activity_notifications_v1";
    private static final String SYSTEM_CHANNEL = "system_notifications_v1";
    private static final long[] MESSAGE_VIBRATION = new long[]{0L, 120L};
    private static final long[] MENTION_VIBRATION = new long[]{0L, 220L, 100L, 220L};
    private static final long[] CALL_VIBRATION = new long[]{0L, 700L, 350L, 700L, 350L, 700L};
    private static final String MESSAGE_GROUP = "yiyunying.chat.messages";
    private static final String SOCIAL_GROUP = "yiyunying.social.notifications";
    private static final String ACTIVITY_GROUP = "yiyunying.activity.notifications";
    private static final String SYSTEM_GROUP = "yiyunying.system.notifications";
    private static final String ACTION_QUICK_REPLY = "xyz.jjmxg.yiyunying.action.QUICK_REPLY";
    private static final String ACTION_CALL_ANSWER = "xyz.jjmxg.yiyunying.action.CALL_ANSWER";
    private static final String ACTION_CALL_HANGUP = "xyz.jjmxg.yiyunying.action.CALL_HANGUP";
    private static final String ACTION_CALL_CANCEL_NOTIFICATION = "xyz.jjmxg.yiyunying.action.CALL_CANCEL_NOTIFICATION";
    private static final String REMOTE_INPUT_TEXT = "reply_text";
    private static final String EXTRA_NOTIFICATION_ID = "notification_id";
    private static final String EXTRA_MESSAGE_TYPE = "message_type";
    private static final String EXTRA_TARGET_ID = "target_id";
    private static final String EXTRA_PEER_ID = "peer_id";
    private static final String EXTRA_CALL_ID = "call_id";
    private static final String EXTRA_CALL_TYPE = "call_type";
    private static final String EXTRA_PEER_NAME = "peer_name";
    private static final String EXTRA_PEER_AVATAR = "peer_avatar";
    private static final int FOREGROUND_ID = 12001;
    private static final int MESSAGE_SUMMARY_ID = 12010;

    private final Handler handler = new Handler(Looper.getMainLooper());
    private final Map<String, Integer> unreadBaseline = new HashMap<>();
    private final Set<String> businessBaseline = new HashSet<>();
    private final Set<String> mutedConversations = new HashSet<>();
    private final Runnable poll = this::poll;
    private final Runnable callPoll = this::pollIncomingCall;
    private RequestHandle request;
    private RequestHandle businessRequest;
    private RequestHandle callRequest;
    private long intervalMs = 5000L;
    private long callIntervalMs = 300L;
    private int callPollFailures;
    private long lastIncomingCallId;
    private int activeCallNotificationId;
    private boolean businessBaselineReady;
    private boolean notificationsEnabled = true;
    private boolean privateNotificationsEnabled = true;
    private boolean groupNotificationsEnabled = true;
    private boolean running;

    public static void start(Context context) {
        ContextCompat.startForegroundService(context, new Intent(context, MessageNotificationService.class));
    }

    public static void stop(Context context) {
        context.stopService(new Intent(context, MessageNotificationService.class));
    }

    public static void cancelIncomingCall(Context context, long callId) {
        if (callId <= 0) return;
        NotificationManager manager = (NotificationManager) context.getSystemService(Context.NOTIFICATION_SERVICE);
        if (manager != null) manager.cancel(callNotificationId(callId));
        Intent intent = new Intent(context, MessageNotificationService.class)
            .setAction(ACTION_CALL_CANCEL_NOTIFICATION)
            .putExtra(EXTRA_CALL_ID, callId);
        try { context.startService(intent); } catch (RuntimeException ignored) { }
    }

    public static int callNotificationId(long callId) {
        return 22000 + (int) (Math.abs(callId) % 10000L);
    }

    @Override public void onCreate() {
        super.onCreate();
        createChannels();
        startForeground(FOREGROUND_ID, serviceNotification());
        running = true;
    }

    @Override public int onStartCommand(Intent intent, int flags, int startId) {
        if (!isUserSession()) {
            stopSelf();
            return START_NOT_STICKY;
        }
        if (intent != null && handleAction(intent)) return START_STICKY;
        handler.removeCallbacks(poll);
        handler.removeCallbacks(callPoll);
        poll();
        callPoll.run();
        return START_STICKY;
    }

    private boolean handleAction(Intent intent) {
        String action = intent.getAction();
        if (ACTION_QUICK_REPLY.equals(action)) {
            Bundle results = RemoteInput.getResultsFromIntent(intent);
            CharSequence value = results == null ? null : results.getCharSequence(REMOTE_INPUT_TEXT);
            String content = value == null ? "" : value.toString().trim();
            if (!content.isEmpty()) sendQuickReply(intent, content);
            return true;
        }
        if (ACTION_CALL_ANSWER.equals(action)) {
            answerCall(intent);
            return true;
        }
        if (ACTION_CALL_HANGUP.equals(action)) {
            hangupCall(intent);
            return true;
        }
        if (ACTION_CALL_CANCEL_NOTIFICATION.equals(action)) {
            cancelIncomingNotification(intent.getLongExtra(EXTRA_CALL_ID, 0));
            return true;
        }
        return false;
    }

    private void sendQuickReply(Intent intent, String content) {
        String type = intent.getStringExtra(EXTRA_MESSAGE_TYPE);
        long targetId = intent.getLongExtra(EXTRA_TARGET_ID, 0);
        long peerId = intent.getLongExtra(EXTRA_PEER_ID, 0);
        JsonObject body = new JsonObject();
        body.addProperty("content", content);
        String path;
        if ("group".equals(type) && targetId > 0) {
            path = "/api/user/chat-rooms/" + targetId + "/messages";
        } else if ("private".equals(type) && peerId > 0) {
            path = "/api/user/messages/private";
            body.addProperty("to_user_id", peerId);
        } else if ("service".equals(type)) {
            path = "/api/user/service/messages";
        } else {
            return;
        }
        AppAccess.from(this).repository().post(path, body, result -> {
            if (!result.isSuccessful()) return;
            NotificationManager manager = getSystemService(NotificationManager.class);
            if (manager != null) manager.cancel(intent.getIntExtra(EXTRA_NOTIFICATION_ID, 0));
            handler.removeCallbacks(poll);
            handler.postDelayed(poll, 500L);
        });
    }

    private void answerCall(Intent intent) {
        long callId = intent.getLongExtra(EXTRA_CALL_ID, 0);
        if (callId <= 0) return;
        cancelIncomingNotification(callId);
        AppAccess.from(this).repository().post("/api/user/voice-calls/" + callId + "/answer",
            new JsonObject(), result -> {
                if (!result.isSuccessful()) return;
                Intent page = VoiceCallActivity.resumeIntent(this, callId,
                    intent.getStringExtra(EXTRA_PEER_NAME), intent.getStringExtra(EXTRA_PEER_AVATAR),
                    intent.getStringExtra(EXTRA_CALL_TYPE));
                startActivity(page);
            });
    }

    private void hangupCall(Intent intent) {
        long callId = intent.getLongExtra(EXTRA_CALL_ID, 0);
        if (callId <= 0) return;
        cancelIncomingNotification(callId);
        AppAccess.from(this).repository().post("/api/user/voice-calls/" + callId + "/hangup",
            new JsonObject(), result -> { });
    }

    private void poll() {
        if (!running || request != null || businessRequest != null || !isUserSession()) return;
        LinkedHashMap<String, String> query = new LinkedHashMap<>();
        query.put("limit", "200");
        request = AppAccess.from(this).repository().get("/api/user/message-center", query, result -> {
            request = null;
            if (!running) return;
            if (result.isAuthenticationFailure()) {
                stopSelf();
                return;
            }
            if (result.isSuccessful()) process(result.dataObject());
            pollBusinessNotifications();
        });
    }

    private void pollBusinessNotifications() {
        if (!running || businessRequest != null || !isUserSession()) {
            scheduleNextPoll();
            return;
        }
        LinkedHashMap<String, String> query = new LinkedHashMap<>();
        query.put("limit", "100");
        businessRequest = AppAccess.from(this).repository().get("/api/user/notifications", query, result -> {
            businessRequest = null;
            if (!running) return;
            if (result.isAuthenticationFailure()) {
                stopSelf();
                return;
            }
            if (result.isSuccessful()) processBusinessNotifications(result.dataObject());
            scheduleNextPoll();
        });
    }

    private void pollIncomingCall() {
        if (!running || callRequest != null || !isUserSession()) {
            scheduleNextCallPoll();
            return;
        }
        callRequest = AppAccess.from(this).repository().get(
            "/api/user/voice-calls/incoming", new LinkedHashMap<>(), result -> {
                callRequest = null;
                if (!running) return;
                if (result.isAuthenticationFailure()) {
                    stopSelf();
                    return;
                }
                if (result.isSuccessful()) {
                    callPollFailures = 0;
                    processIncomingCall(result.dataObject());
                } else {
                    callPollFailures = Math.min(4, callPollFailures + 1);
                }
                scheduleNextCallPoll();
            });
    }

    private void scheduleNextCallPoll() {
        if (!running) return;
        handler.removeCallbacks(callPoll);
        long failureDelay = callPollFailures <= 0
            ? callIntervalMs
            : Math.min(4000L, callIntervalMs * (1L << callPollFailures));
        handler.postDelayed(callPoll, failureDelay);
    }

    private void scheduleNextPoll() {
        if (!running) return;
        handler.removeCallbacks(poll);
        handler.postDelayed(poll, intervalMs);
    }

    private void process(JsonObject data) {
        long configured = Jsons.longValue(data, "poll_interval_ms");
        if (configured > 0) intervalMs = Math.max(1000L, Math.min(60000L, configured));
        JsonObject settings = Jsons.object(data, "settings");
        boolean enabled = bool(settings, "system_notification_enabled", true);
        boolean privateEnabled = bool(settings, "private_notification_enabled", true);
        boolean groupEnabled = bool(settings, "group_notification_enabled", true);
        notificationsEnabled = enabled;
        privateNotificationsEnabled = privateEnabled;
        groupNotificationsEnabled = groupEnabled;
        JsonArray items = Jsons.array(data, "items");
        Map<String, Integer> next = new HashMap<>();
        Set<String> nextMuted = new HashSet<>();
        for (JsonElement element : items) {
            if (!element.isJsonObject()) continue;
            JsonObject item = element.getAsJsonObject();
            String type = Jsons.string(item, "type");
            String key = type + ":" + Jsons.longValue(item, "target_id");
            int unread = Jsons.intValue(item, "unread_count", 0);
            next.put(key, unread);
            if (bool(item, "is_muted", false)) nextMuted.add(key);
            int before = unreadBaseline.getOrDefault(key, 0);
            if (unread > before) broadcastMessageChanged(type, Jsons.longValue(item, "target_id"));
            boolean typeEnabled = "group".equals(type) ? groupEnabled
                : ("private".equals(type) ? privateEnabled : enabled);
            if (enabled && typeEnabled && !bool(item, "is_muted", false) && unread > before) {
                notifyMessage(item, key.hashCode());
            } else if (unread <= 0 || !enabled || !typeEnabled || bool(item, "is_muted", false)) {
                NotificationManager manager = getSystemService(NotificationManager.class);
                if (manager != null) manager.cancel(key.hashCode());
            }
        }
        unreadBaseline.clear();
        unreadBaseline.putAll(next);
        mutedConversations.clear();
        mutedConversations.addAll(nextMuted);
        notifyMessageSummary(items, enabled, privateEnabled, groupEnabled);
    }

    private void broadcastMessageChanged(String type, long targetId) {
        Intent intent = new Intent(ACTION_MESSAGES_CHANGED)
            .setPackage(getPackageName())
            .putExtra(EXTRA_CHANGED_TYPE, type)
            .putExtra(EXTRA_CHANGED_TARGET_ID, targetId);
        sendBroadcast(intent);
    }

    private void processIncomingCall(JsonObject data) {
        JsonObject call = Jsons.object(data, "call");
        long callId = Jsons.longValue(call, "id");
        if (callId <= 0) {
            lastIncomingCallId = 0;
            cancelIncomingNotification();
            return;
        }
        if (callId == lastIncomingCallId) return;
        lastIncomingCallId = callId;
        notifyIncomingCall(call);
    }

    private void processBusinessNotifications(JsonObject data) {
        JsonArray items = Jsons.array(data, "items");
        Set<String> next = new HashSet<>();
        for (JsonElement element : items) {
            if (!element.isJsonObject()) continue;
            JsonObject item = element.getAsJsonObject();
            String key = Jsons.string(item, "source_type") + ":" + Jsons.longValue(item, "source_id");
            next.add(key);
            String type = Jsons.string(item, "notification_type");
            boolean mention = "chat_mention".equals(type) || "forum_mention".equals(type);
            if (businessBaselineReady && !bool(item, "is_read", false) && !businessBaseline.contains(key)) {
                if (mention) notifyMention(item, key.hashCode());
                else notifyBusiness(item, key.hashCode());
            }
        }
        businessBaseline.clear();
        businessBaseline.addAll(next);
        businessBaselineReady = true;
    }

    private void notifyMessage(JsonObject item, int notificationId) {
        String type = Jsons.string(item, "type");
        String title = Jsons.string(item, "title");
        String prefix;
        if ("group".equals(type)) prefix = "群聊";
        else if ("service".equals(type)) prefix = "在线客服";
        else prefix = bool(item, "is_stranger", false) ? "陌生人消息" : "好友消息";
        String content = Jsons.string(item, "last_message");
        Intent target;
        if ("group".equals(type)) {
            target = ChatActivity.roomIntent(this, Jsons.longValue(item, "target_id"), title);
        } else if ("private".equals(type)) {
            target = ChatActivity.conversationIntent(this, Jsons.longValue(item, "target_id"),
                Jsons.longValue(item, "peer_user_id"), title);
        } else if ("service".equals(type)) {
            target = ChatActivity.userServiceIntent(this);
        } else {
            target = new Intent(this, MainActivity.class);
        }
        PendingIntent pending = PendingIntent.getActivity(this, notificationId, target,
            PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);
        Intent replyIntent = new Intent(this, MessageNotificationService.class)
            .setAction(ACTION_QUICK_REPLY)
            .putExtra(EXTRA_NOTIFICATION_ID, notificationId)
            .putExtra(EXTRA_MESSAGE_TYPE, type)
            .putExtra(EXTRA_TARGET_ID, Jsons.longValue(item, "target_id"))
            .putExtra(EXTRA_PEER_ID, Jsons.longValue(item, "peer_user_id"));
        PendingIntent replyPending = PendingIntent.getService(this, notificationId + 40000, replyIntent,
            PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_MUTABLE);
        RemoteInput remoteInput = new RemoteInput.Builder(REMOTE_INPUT_TEXT)
            .setLabel("回复消息")
            .build();
        NotificationCompat.Action replyAction = new NotificationCompat.Action.Builder(
            R.drawable.ic_send, "回复", replyPending).addRemoteInput(remoteInput).build();
        Person me = new Person.Builder().setName("我").build();
        NotificationCompat.MessagingStyle messageStyle = new NotificationCompat.MessagingStyle(me)
            .setConversationTitle(prefix + " · " + title)
            .setGroupConversation("group".equals(type));
        JsonArray notificationMessages = Jsons.array(item, "notification_messages");
        int position = 0;
        for (JsonElement element : notificationMessages) {
            if (!element.isJsonObject()) continue;
            JsonObject message = element.getAsJsonObject();
            String senderName = Jsons.string(message, "sender_name");
            if (senderName.isEmpty()) senderName = title.isEmpty() ? prefix : title;
            String text = Jsons.string(message, "content");
            if (text.isEmpty()) text = "[新消息]";
            Person sender = new Person.Builder().setName(senderName).build();
            messageStyle.addMessage(text, notificationTime(Jsons.string(message, "created_at"), position++), sender);
        }
        if (position == 0) {
            Person sender = new Person.Builder().setName(title.isEmpty() ? prefix : title).build();
            messageStyle.addMessage(content.isEmpty() ? "[新消息]" : content,
                System.currentTimeMillis(), sender);
        }
        Notification notification = new NotificationCompat.Builder(this, MESSAGE_CHANNEL)
            .setSmallIcon(NotificationIconResolver.smallIcon(this))
            .setContentTitle(prefix + " · " + title)
            .setContentText(content)
            .setStyle(messageStyle)
            .setNumber(Math.max(1, Jsons.intValue(item, "unread_count", 1)))
            .setContentIntent(pending)
            .setAutoCancel(true)
            .setOnlyAlertOnce(false)
            .setGroup(MESSAGE_GROUP)
            .setGroupAlertBehavior(NotificationCompat.GROUP_ALERT_CHILDREN)
            .setCategory(NotificationCompat.CATEGORY_MESSAGE)
            .setPriority(NotificationCompat.PRIORITY_DEFAULT)
            .setVibrate(MESSAGE_VIBRATION)
            .addAction(replyAction)
            .build();
        NotificationManager manager = getSystemService(NotificationManager.class);
        if (manager != null) manager.notify(notificationId, notification);
    }

    private long notificationTime(String value, int fallbackOffset) {
        if (value != null && !value.trim().isEmpty()) {
            String[] patterns = {"yyyy-MM-dd HH:mm:ss", "yyyy-MM-dd'T'HH:mm:ss"};
            for (String pattern : patterns) {
                try {
                    Date date = new SimpleDateFormat(pattern, Locale.CHINA).parse(value.trim());
                    if (date != null) return date.getTime();
                } catch (java.text.ParseException ignored) { }
            }
        }
        return System.currentTimeMillis() + fallbackOffset;
    }

    private void notifyMessageSummary(JsonArray items, boolean enabled, boolean privateEnabled, boolean groupEnabled) {
        NotificationManager manager = getSystemService(NotificationManager.class);
        if (manager == null) return;
        NotificationCompat.InboxStyle style = new NotificationCompat.InboxStyle();
        int conversations = 0;
        int unreadTotal = 0;
        for (JsonElement element : items) {
            if (!element.isJsonObject()) continue;
            JsonObject item = element.getAsJsonObject();
            int unread = Jsons.intValue(item, "unread_count", 0);
            String type = Jsons.string(item, "type");
            boolean typeEnabled = "group".equals(type) ? groupEnabled
                : ("private".equals(type) ? privateEnabled : enabled);
            if (unread <= 0 || !enabled || !typeEnabled || bool(item, "is_muted", false)) continue;
            conversations++;
            unreadTotal += unread;
            style.addLine(Jsons.string(item, "title") + "：" + Jsons.string(item, "last_message"));
        }
        if (conversations <= 1) {
            manager.cancel(MESSAGE_SUMMARY_ID);
            return;
        }
        Intent target = new Intent(this, MainActivity.class);
        PendingIntent pending = PendingIntent.getActivity(this, MESSAGE_SUMMARY_ID, target,
            PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);
        style.setBigContentTitle(conversations + " 个会话有新消息")
            .setSummaryText("共 " + unreadTotal + " 条未读消息");
        Notification summary = new NotificationCompat.Builder(this, MESSAGE_CHANNEL)
            .setSmallIcon(NotificationIconResolver.smallIcon(this))
            .setContentTitle(conversations + " 个会话有新消息")
            .setContentText("共 " + unreadTotal + " 条未读消息")
            .setStyle(style)
            .setContentIntent(pending)
            .setGroup(MESSAGE_GROUP)
            .setGroupSummary(true)
            .setSilent(true)
            .setAutoCancel(true)
            .setCategory(NotificationCompat.CATEGORY_MESSAGE)
            .build();
        manager.notify(MESSAGE_SUMMARY_ID, summary);
    }

    private void notifyBusiness(JsonObject item, int stableId) {
        int notificationId = 32000 + Math.abs(stableId % 20000);
        String title = Jsons.string(item, "title");
        String content = Jsons.string(item, "content");
        String center = normalizeNotificationCenter(Jsons.string(item, "center_key"));
        String channel = notificationChannel(center);
        String group = notificationGroup(center);
        Intent target = MainActivity.notificationIntent(this, center);
        PendingIntent pending = PendingIntent.getActivity(this, notificationId, target,
            PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);
        Notification notification = new NotificationCompat.Builder(this, channel)
            .setSmallIcon(NotificationIconResolver.smallIcon(this))
            .setContentTitle(title.isEmpty() ? "易运盈通知" : title)
            .setContentText(content)
            .setStyle(new NotificationCompat.BigTextStyle().bigText(content))
            .setContentIntent(pending)
            .setAutoCancel(true)
            .setOnlyAlertOnce(false)
            .setGroup(group)
            .setCategory(NotificationCompat.CATEGORY_EVENT)
            .setPriority(NotificationCompat.PRIORITY_DEFAULT)
            .setVibrate(MESSAGE_VIBRATION)
            .build();
        NotificationManager manager = getSystemService(NotificationManager.class);
        if (manager != null) manager.notify(notificationId, notification);
    }

    private void notifyMention(JsonObject item, int stableId) {
        int notificationId = 52000 + Math.abs(stableId % 10000);
        String type = Jsons.string(item, "notification_type");
        String title = Jsons.string(item, "title");
        String content = Jsons.string(item, "content");
        JsonObject data = notificationData(item);
        Intent target;
        String conversationKey = "";
        String replyType = "";
        long replyTargetId = 0L;
        long replyPeerId = 0L;
        if ("forum_mention".equals(type)) {
            if (!notificationsEnabled) return;
            target = ForumPostActivity.mentionIntent(this, Jsons.longValue(data, "post_id"),
                Jsons.longValue(data, "comment_id"));
        } else if (Jsons.longValue(data, "room_id") > 0) {
            replyType = "group";
            replyTargetId = Jsons.longValue(data, "room_id");
            conversationKey = "group:" + replyTargetId;
            if (!notificationsEnabled || !groupNotificationsEnabled || mutedConversations.contains(conversationKey)) return;
            target = ChatActivity.focusMessage(
                ChatActivity.roomIntent(this, replyTargetId,
                    valueOr(data, "room_name", "群聊")),
                Jsons.longValue(data, "message_id"));
        } else {
            replyType = "private";
            replyTargetId = Jsons.longValue(data, "conversation_id");
            replyPeerId = Jsons.longValue(data, "sender_user_id");
            conversationKey = "private:" + replyTargetId;
            if (!notificationsEnabled || !privateNotificationsEnabled || mutedConversations.contains(conversationKey)) return;
            target = ChatActivity.focusMessage(
                ChatActivity.conversationIntent(this, replyTargetId,
                    replyPeerId, valueOr(data, "sender_name", "好友消息")),
                Jsons.longValue(data, "message_id"));
        }
        PendingIntent pending = PendingIntent.getActivity(this, notificationId, target,
            PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);
        NotificationCompat.Builder builder = new NotificationCompat.Builder(this, MENTION_CHANNEL)
            .setSmallIcon(NotificationIconResolver.smallIcon(this))
            .setContentTitle("@你 · " + (title.isEmpty() ? "有人提到了你" : title))
            .setContentText(content)
            .setStyle(new NotificationCompat.BigTextStyle().bigText(content + "\n点击后定位到被提及的位置"))
            .setContentIntent(pending)
            .setAutoCancel(true)
            .setOnlyAlertOnce(false)
            .setCategory(NotificationCompat.CATEGORY_MESSAGE)
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setVisibility(NotificationCompat.VISIBILITY_PRIVATE)
            .setVibrate(MENTION_VIBRATION);
        if (!replyType.isEmpty()) {
            Intent replyIntent = new Intent(this, MessageNotificationService.class)
                .setAction(ACTION_QUICK_REPLY)
                .putExtra(EXTRA_NOTIFICATION_ID, notificationId)
                .putExtra(EXTRA_MESSAGE_TYPE, replyType)
                .putExtra(EXTRA_TARGET_ID, replyTargetId)
                .putExtra(EXTRA_PEER_ID, replyPeerId);
            PendingIntent replyPending = PendingIntent.getService(this, notificationId + 40000, replyIntent,
                PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_MUTABLE);
            RemoteInput remoteInput = new RemoteInput.Builder(REMOTE_INPUT_TEXT).setLabel("回复 @ 消息").build();
            builder.addAction(new NotificationCompat.Action.Builder(
                R.drawable.ic_send, "回复", replyPending).addRemoteInput(remoteInput).build());
        }
        Notification notification = builder.build();
        NotificationManager manager = getSystemService(NotificationManager.class);
        if (manager != null) {
            if (!conversationKey.isEmpty()) manager.cancel(conversationKey.hashCode());
            manager.notify(notificationId, notification);
        }
    }

    private JsonObject notificationData(JsonObject item) {
        String raw = Jsons.string(item, "data_json");
        if (raw.isEmpty()) return new JsonObject();
        try {
            JsonElement parsed = Jsons.parse(raw);
            return parsed.isJsonObject() ? parsed.getAsJsonObject() : new JsonObject();
        } catch (RuntimeException ignored) {
            return new JsonObject();
        }
    }

    private String valueOr(JsonObject object, String key, String fallback) {
        String value = Jsons.string(object, key);
        return value.isEmpty() ? fallback : value;
    }

    private void notifyIncomingCall(JsonObject call) {
        long callId = Jsons.longValue(call, "id");
        String peerName = Jsons.string(call, "peer_name");
        if (peerName.isEmpty()) peerName = "好友";
        String peerAvatar = Jsons.string(call, "peer_avatar");
        String callType = "video".equals(Jsons.string(call, "call_type")) ? "video" : "audio";
        boolean video = "video".equals(callType);
        int notificationId = callNotificationId(callId);
        if (activeCallNotificationId != 0 && activeCallNotificationId != notificationId) {
            NotificationManager staleManager = getSystemService(NotificationManager.class);
            if (staleManager != null) staleManager.cancel(activeCallNotificationId);
        }
        activeCallNotificationId = notificationId;
        Intent target = VoiceCallActivity.incomingIntent(this, callId, peerName, peerAvatar, callType)
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TOP | Intent.FLAG_ACTIVITY_SINGLE_TOP);
        PendingIntent pending = PendingIntent.getActivity(this, notificationId, target,
            PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);
        Intent answerIntent = new Intent(this, MessageNotificationService.class)
            .setAction(ACTION_CALL_ANSWER)
            .putExtra(EXTRA_CALL_ID, callId)
            .putExtra(EXTRA_CALL_TYPE, callType)
            .putExtra(EXTRA_PEER_NAME, peerName)
            .putExtra(EXTRA_PEER_AVATAR, peerAvatar);
        PendingIntent answerPending = PendingIntent.getService(this, notificationId + 10000, answerIntent,
            PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);
        Intent hangupIntent = new Intent(this, MessageNotificationService.class)
            .setAction(ACTION_CALL_HANGUP)
            .putExtra(EXTRA_CALL_ID, callId);
        PendingIntent hangupPending = PendingIntent.getService(this, notificationId + 20000, hangupIntent,
            PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);
        Notification notification = new NotificationCompat.Builder(this, CALL_CHANNEL)
            .setSmallIcon(NotificationIconResolver.smallIcon(this))
            .setContentTitle(peerName + (video ? " 邀请你视频通话" : " 邀请你语音通话"))
            .setContentText(video ? "视频通话来电" : "语音通话来电")
            .setContentIntent(pending)
            .setFullScreenIntent(pending, true)
            .addAction(R.drawable.ic_call_end, "挂断", hangupPending)
            .addAction(video ? R.drawable.ic_videocam : R.drawable.ic_phone, "接听", answerPending)
            .setCategory(NotificationCompat.CATEGORY_CALL)
            .setPriority(NotificationCompat.PRIORITY_MAX)
            .setVisibility(NotificationCompat.VISIBILITY_PUBLIC)
            .setOngoing(true)
            .setTimeoutAfter(60000L)
            .setVibrate(CALL_VIBRATION)
            .build();
        // Keep ringing until the call is answered, declined or times out. The active-call
        // foreground notification is intentionally started only after the call is active.
        notification.flags |= Notification.FLAG_INSISTENT;
        NotificationManager manager = getSystemService(NotificationManager.class);
        if (manager != null) manager.notify(notificationId, notification);
        wakeForIncomingCall();
    }

    @SuppressWarnings("deprecation")
    private void wakeForIncomingCall() {
        PowerManager power = (PowerManager) getSystemService(POWER_SERVICE);
        if (power == null) return;
        PowerManager.WakeLock lock = power.newWakeLock(
            PowerManager.SCREEN_BRIGHT_WAKE_LOCK | PowerManager.ACQUIRE_CAUSES_WAKEUP,
            getPackageName() + ":incoming_call");
        lock.acquire(5000L);
    }

    private void cancelIncomingNotification() {
        cancelIncomingNotification(0);
    }

    private void cancelIncomingNotification(long callId) {
        NotificationManager manager = getSystemService(NotificationManager.class);
        if (manager != null) {
            if (callId > 0) manager.cancel(callNotificationId(callId));
            if (activeCallNotificationId != 0) manager.cancel(activeCallNotificationId);
        }
        if (callId <= 0 || activeCallNotificationId == callNotificationId(callId)) lastIncomingCallId = 0;
        activeCallNotificationId = 0;
    }

    private Notification serviceNotification() {
        PendingIntent pending = PendingIntent.getActivity(this, 0, new Intent(this, MainActivity.class),
            PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);
        return new NotificationCompat.Builder(this, SERVICE_CHANNEL)
            .setSmallIcon(NotificationIconResolver.smallIcon(this))
            .setContentTitle("易运盈消息通知")
            .setContentText("正在接收好友、群聊和客服消息")
            .setContentIntent(pending)
            .setOngoing(true)
            .setSilent(true)
            .setCategory(NotificationCompat.CATEGORY_SERVICE)
            .setPriority(NotificationCompat.PRIORITY_LOW)
            .build();
    }

    private void createChannels() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return;
        NotificationManager manager = getSystemService(NotificationManager.class);
        if (manager == null) return;
        NotificationChannel service = new NotificationChannel(
            SERVICE_CHANNEL, "消息后台服务", NotificationManager.IMPORTANCE_LOW);
        service.setDescription("保持好友、群聊和客服消息接收");
        NotificationChannel messages = new NotificationChannel(
            MESSAGE_CHANNEL, "普通消息提醒", NotificationManager.IMPORTANCE_DEFAULT);
        messages.setDescription("好友私聊、陌生人私聊、群聊和客服的新消息");
        messages.setSound(RingtoneManager.getDefaultUri(RingtoneManager.TYPE_NOTIFICATION),
            new AudioAttributes.Builder().setUsage(AudioAttributes.USAGE_NOTIFICATION)
                .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION).build());
        messages.enableVibration(true);
        messages.setVibrationPattern(MESSAGE_VIBRATION);
        messages.setShowBadge(true);
        NotificationChannel mentions = new NotificationChannel(
            MENTION_CHANNEL, "有人提到我", NotificationManager.IMPORTANCE_HIGH);
        mentions.setDescription("群聊、私聊和论坛中有人使用 @ 提到你");
        mentions.setLockscreenVisibility(Notification.VISIBILITY_PRIVATE);
        mentions.setSound(RingtoneManager.getDefaultUri(RingtoneManager.TYPE_NOTIFICATION),
            new AudioAttributes.Builder().setUsage(AudioAttributes.USAGE_NOTIFICATION_COMMUNICATION_INSTANT)
                .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION).build());
        mentions.enableVibration(true);
        mentions.setVibrationPattern(MENTION_VIBRATION);
        mentions.setShowBadge(true);
        NotificationChannel calls = new NotificationChannel(
            CALL_CHANNEL, "语音和视频通话", NotificationManager.IMPORTANCE_HIGH);
        calls.setDescription("语音通话和视频通话的来电提醒");
        calls.setLockscreenVisibility(Notification.VISIBILITY_PUBLIC);
        calls.enableVibration(true);
        calls.setVibrationPattern(CALL_VIBRATION);
        calls.setShowBadge(false);
        calls.setSound(RingtoneManager.getDefaultUri(RingtoneManager.TYPE_RINGTONE),
            new AudioAttributes.Builder().setUsage(AudioAttributes.USAGE_NOTIFICATION_RINGTONE)
                .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION).build());
        NotificationChannel social = businessChannel(
            SOCIAL_CHANNEL, "社交通知", "好友、群聊、聊天室、论坛、悬赏和内容互动");
        NotificationChannel activity = businessChannel(
            ACTIVITY_CHANNEL, "活动通知", "抽奖、红包、投票、订单、兑换和余额活动");
        NotificationChannel system = businessChannel(
            SYSTEM_CHANNEL, "系统通知", "公告、维护、更新和账号安全通知");
        manager.createNotificationChannel(service);
        manager.createNotificationChannel(messages);
        manager.createNotificationChannel(mentions);
        manager.createNotificationChannel(calls);
        manager.createNotificationChannel(social);
        manager.createNotificationChannel(activity);
        manager.createNotificationChannel(system);
    }

    @RequiresApi(Build.VERSION_CODES.O)
    private NotificationChannel businessChannel(String id, String name, String description) {
        NotificationChannel channel = new NotificationChannel(id, name, NotificationManager.IMPORTANCE_DEFAULT);
        channel.setDescription(description);
        channel.setLockscreenVisibility(Notification.VISIBILITY_PRIVATE);
        channel.setSound(RingtoneManager.getDefaultUri(RingtoneManager.TYPE_NOTIFICATION),
            new AudioAttributes.Builder().setUsage(AudioAttributes.USAGE_NOTIFICATION)
                .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION).build());
        channel.enableVibration(true);
        channel.setVibrationPattern(MESSAGE_VIBRATION);
        channel.setShowBadge(true);
        return channel;
    }

    private static String normalizeNotificationCenter(String center) {
        if ("activity".equals(center) || "system".equals(center)) return center;
        return "social";
    }

    private static String notificationChannel(String center) {
        if ("activity".equals(center)) return ACTIVITY_CHANNEL;
        if ("system".equals(center)) return SYSTEM_CHANNEL;
        return SOCIAL_CHANNEL;
    }

    private static String notificationGroup(String center) {
        if ("activity".equals(center)) return ACTIVITY_GROUP;
        if ("system".equals(center)) return SYSTEM_GROUP;
        return SOCIAL_GROUP;
    }

    private boolean isUserSession() {
        return AppAccess.from(this).session().isAuthenticated()
            && AppAccess.from(this).session().role() == Role.USER;
    }

    private static boolean bool(JsonObject object, String key, boolean fallback) {
        try {
            return object.has(key) ? object.get(key).getAsBoolean() : fallback;
        } catch (RuntimeException ignored) {
            return fallback;
        }
    }

    @Nullable @Override public IBinder onBind(Intent intent) {
        return null;
    }

    @Override public void onTaskRemoved(Intent rootIntent) {
        if (isUserSession()) {
            Intent restart = new Intent(getApplicationContext(), MessageNotificationService.class);
            PendingIntent pending = PendingIntent.getService(getApplicationContext(), 12002, restart,
                PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);
            AlarmManager alarm = (AlarmManager) getSystemService(ALARM_SERVICE);
            if (alarm != null) {
                alarm.set(AlarmManager.ELAPSED_REALTIME,
                    android.os.SystemClock.elapsedRealtime() + 3000L, pending);
            }
        }
        super.onTaskRemoved(rootIntent);
    }

    @Override public void onDestroy() {
        running = false;
        handler.removeCallbacks(poll);
        handler.removeCallbacks(callPoll);
        if (request != null) request.cancel();
        if (businessRequest != null) businessRequest.cancel();
        if (callRequest != null) callRequest.cancel();
        cancelIncomingNotification();
        super.onDestroy();
    }
}
