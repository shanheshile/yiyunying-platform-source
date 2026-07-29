package xyz.jjmxg.yiyunying.ui.notification;

import android.app.Activity;
import android.os.Bundle;
import android.os.SystemClock;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;

import androidx.annotation.NonNull;
import androidx.annotation.Nullable;
import androidx.appcompat.app.AlertDialog;
import androidx.lifecycle.Lifecycle;
import androidx.recyclerview.widget.LinearLayoutManager;

import com.google.android.material.snackbar.Snackbar;
import com.google.gson.JsonArray;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;

import java.util.ArrayList;
import java.util.HashMap;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.core.CrashReporter;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.data.api.RequestHandle;
import xyz.jjmxg.yiyunying.databinding.FragmentNotificationCenterBinding;
import xyz.jjmxg.yiyunying.ui.common.BaseFragment;
import xyz.jjmxg.yiyunying.ui.forum.ForumPostActivity;
import xyz.jjmxg.yiyunying.ui.main.MainActivity;
import xyz.jjmxg.yiyunying.ui.profile.UserProfileActivity;
import xyz.jjmxg.yiyunying.ui.wallet.WalletLedgerActivity;

public final class NotificationCenterFragment extends BaseFragment {
    private static final String ARG_CENTER = "center";
    private static final long NOTIFICATION_CLICK_DEBOUNCE_MS = 500L;

    private FragmentNotificationCenterBinding binding;
    private NotificationCenterAdapter adapter;
    private final List<JsonObject> groups = new ArrayList<>();
    private final List<JsonObject> notifications = new ArrayList<>();
    private final List<JsonObject> centers = new ArrayList<>();
    private final Map<String, Boolean> collapsed = new HashMap<>();
    private RequestHandle request;
    private RequestHandle actionRequest;
    private boolean initializedCollapseState;
    private boolean changingCenter;
    private String selectedCenter = "social";
    private String openedNotificationKey = "";
    private long lastNotificationClickAt;

    public static NotificationCenterFragment newInstance() {
        return newInstance("social");
    }

    public static NotificationCenterFragment newInstance(String center) {
        NotificationCenterFragment fragment = new NotificationCenterFragment();
        Bundle arguments = new Bundle();
        arguments.putString(ARG_CENTER, normalizeCenter(center));
        fragment.setArguments(arguments);
        return fragment;
    }

    @Nullable @Override
    public View onCreateView(@NonNull LayoutInflater inflater, @Nullable ViewGroup container, @Nullable Bundle state) {
        binding = FragmentNotificationCenterBinding.inflate(inflater, container, false);
        selectedCenter = normalizeCenter(getArguments() == null ? null : getArguments().getString(ARG_CENTER));
        adapter = new NotificationCenterAdapter(new NotificationCenterAdapter.Listener() {
            @Override public void onNotificationClick(JsonObject notification) { showNotification(notification); }
            @Override public void onGroupToggle(JsonObject group) { toggleGroup(group); }
            @Override public void onGroupRead(JsonObject group) { readGroup(group); }
        });
        binding.recycler.setLayoutManager(new LinearLayoutManager(requireContext()));
        binding.recycler.setAdapter(adapter);
        binding.swipeRefresh.setOnRefreshListener(() -> load(false));
        binding.readAll.setOnClickListener(view -> readAll());
        binding.retryButton.setOnClickListener(view -> load(true));
        changingCenter = true;
        binding.centerTabs.check(centerButtonId(selectedCenter));
        changingCenter = false;
        binding.centerTabs.addOnButtonCheckedListener((group, checkedId, isChecked) -> {
            if (isChecked && !changingCenter) switchCenter(centerFromButton(checkedId));
        });
        load(true);
        return binding.getRoot();
    }

    private void load(boolean full) {
        if (binding == null || request != null) return;
        if (full) binding.progress.setVisibility(View.VISIBLE);
        LinkedHashMap<String, String> params = new LinkedHashMap<>();
        params.put("limit", "200");
        params.put("center", selectedCenter);
        app().repository().getCached("/api/user/notifications", params, cached -> {
            if (binding == null || !cached.isSuccessful()) return;
            groups.clear();
            notifications.clear();
            centers.clear();
            JsonObject data = cached.dataObject();
            String responseCenter = Jsons.string(data, "selected_center");
            if (!responseCenter.isEmpty()) selectedCenter = normalizeCenter(responseCenter);
            for (JsonElement element : Jsons.array(data, "groups")) {
                if (element.isJsonObject()) groups.add(element.getAsJsonObject());
            }
            for (JsonElement element : Jsons.array(data, "items")) {
                if (element.isJsonObject()) notifications.add(element.getAsJsonObject());
            }
            for (JsonElement element : Jsons.array(data, "centers")) {
                if (element.isJsonObject()) centers.add(element.getAsJsonObject());
            }
            initializeCollapseState();
            render(currentUnread());
            binding.progress.setVisibility(View.GONE);
        });
        request = app().repository().get("/api/user/notifications", params, result -> {
            request = null;
            if (binding == null) return;
            binding.progress.setVisibility(View.GONE);
            binding.swipeRefresh.setRefreshing(false);
            if (handleFailure(result, binding.getRoot())) {
                binding.errorState.setVisibility(groups.isEmpty() ? View.VISIBLE : View.GONE);
                return;
            }
            binding.errorState.setVisibility(View.GONE);
            groups.clear();
            notifications.clear();
            centers.clear();
            JsonObject data = result.dataObject();
            String responseCenter = Jsons.string(data, "selected_center");
            if (!responseCenter.isEmpty()) selectedCenter = normalizeCenter(responseCenter);
            for (JsonElement element : Jsons.array(data, "groups")) {
                if (element.isJsonObject()) groups.add(element.getAsJsonObject());
            }
            for (JsonElement element : Jsons.array(data, "items")) {
                if (element.isJsonObject()) notifications.add(element.getAsJsonObject());
            }
            for (JsonElement element : Jsons.array(data, "centers")) {
                if (element.isJsonObject()) centers.add(element.getAsJsonObject());
            }
            initializeCollapseState();
            render(currentUnread());
        });
    }

    private void switchCenter(String center) {
        String next = normalizeCenter(center);
        if (next.equals(selectedCenter)) return;
        if (request != null) {
            request.cancel();
            request = null;
        }
        selectedCenter = next;
        groups.clear();
        notifications.clear();
        collapsed.clear();
        initializedCollapseState = false;
        adapter.submit(new ArrayList<>());
        renderCenterTabs();
        load(true);
    }

    private void initializeCollapseState() {
        if (initializedCollapseState) return;
        initializedCollapseState = true;
        boolean openedUnread = false;
        for (JsonObject group : groups) {
            String key = Jsons.string(group, "key");
            boolean hasUnread = Jsons.intValue(group, "unread_count", 0) > 0;
            boolean shouldCollapse = !hasUnread || openedUnread;
            collapsed.put(key, shouldCollapse);
            if (hasUnread && !openedUnread) openedUnread = true;
        }
    }

    private void render(int unreadCount) {
        if (binding == null) return;
        List<JsonObject> rows = new ArrayList<>();
        for (JsonObject group : groups) {
            JsonObject header = group.deepCopy();
            String key = Jsons.string(header, "key");
            boolean isCollapsed = collapsed.getOrDefault(key, true);
            header.addProperty("row_type", "group");
            header.addProperty("collapsed", isCollapsed);
            rows.add(header);
            if (!isCollapsed) {
                for (JsonObject notification : notifications) {
                    if (key.equals(Jsons.string(notification, "group_key"))) {
                        rows.add(notification.deepCopy());
                    }
                }
            }
        }
        adapter.submit(rows);
        int total = 0;
        for (JsonObject group : groups) total += Jsons.intValue(group, "total_count", 0);
        binding.summary.setText(centerName(selectedCenter) + " · " + total + " 条");
        binding.unreadSummary.setText(unreadCount > 0 ? "还有 " + unreadCount + " 条未读" : "当前分类均已读");
        binding.readAll.setEnabled(unreadCount > 0 && actionRequest == null);
        binding.emptyState.setVisibility(groups.isEmpty() ? View.VISIBLE : View.GONE);
        renderCenterTabs();
    }

    private void toggleGroup(JsonObject group) {
        String key = Jsons.string(group, "key");
        collapsed.put(key, !collapsed.getOrDefault(key, true));
        render(currentUnread());
    }

    private void showNotification(JsonObject item) {
        if (item == null || binding == null || !isAdded()) return;
        Activity activity = getActivity();
        if (activity == null || activity.isFinishing() || activity.isDestroyed()) return;
        if (!getViewLifecycleOwner().getLifecycle().getCurrentState().isAtLeast(Lifecycle.State.STARTED)) return;

        JsonObject snapshot = item.deepCopy();
        String notificationKey = notificationKey(snapshot);
        long now = SystemClock.elapsedRealtime();
        if (notificationKey.equals(openedNotificationKey)
            || now - lastNotificationClickAt < NOTIFICATION_CLICK_DEBOUNCE_MS) {
            return;
        }
        lastNotificationClickAt = now;

        try {
            JsonObject payload = NotificationDetailDialog.payload(snapshot);
            Runnable action = notificationAction(activity, snapshot, payload);
            AlertDialog dialog = NotificationDetailDialog.show(activity, snapshot,
                action == null ? null : notificationActionLabel(snapshot, payload), action);
            if (dialog == null) return;
            openedNotificationKey = notificationKey;
            dialog.setOnDismissListener(ignored -> {
                if (notificationKey.equals(openedNotificationKey)) openedNotificationKey = "";
            });
            if (!booleanValue(snapshot, "is_read")) readOne(snapshot);
        } catch (RuntimeException | LinkageError exception) {
            openedNotificationKey = "";
            CrashReporter.record("打开通知详情", exception);
            if (binding != null && binding.getRoot().isAttachedToWindow()) {
                Snackbar.make(binding.getRoot(), "通知详情暂时无法打开，请重试", Snackbar.LENGTH_LONG).show();
            }
        }
    }

    private Runnable notificationAction(Activity activity, JsonObject item, JsonObject payload) {
        if (isLifecycleNotification(item, payload)) return null;
        String group = Jsons.string(item, "group_key");
        long postId = firstPositive(payload, "post_id", "forum_post_id", "thread_id", "target_post_id");
        if (postId <= 0 && ("forums".equals(group) || "comments".equals(group) || "likes".equals(group))
            && containsAny(notificationSource(item, payload), "post", "forum", "comment", "reply")) {
            postId = firstPositive(payload, "target_id", "content_id");
        }
        long commentId = firstPositive(payload, "comment_id", "forum_comment_id", "reply_id", "target_comment_id");
        if (postId > 0) {
            long targetPostId = postId;
            long targetCommentId = commentId;
            return guardedNotificationAction(activity, () -> {
                if (targetCommentId > 0) {
                    activity.startActivity(ForumPostActivity.mentionIntent(activity, targetPostId, targetCommentId));
                } else {
                    ForumPostActivity.open(activity, targetPostId);
                }
            });
        }
        if ("wallet".equals(group)) {
            String category = walletCategory(notificationSource(item, payload));
            return guardedNotificationAction(activity, () -> WalletLedgerActivity.open(activity, category));
        }
        String moduleId = relatedModule(group);
        long recordId = relatedRecordId(group, payload);
        if (!moduleId.isEmpty() && !"social".equals(group)) {
            return guardedNotificationAction(activity,
                () -> activity.startActivity(MainActivity.moduleIntent(activity, moduleId, recordId)));
        }
        long userId = firstPositive(payload, "user_id", "from_user_id", "actor_user_id", "follower_user_id");
        if (userId > 0) {
            return guardedNotificationAction(activity, () -> UserProfileActivity.open(activity, userId));
        }
        return moduleId.isEmpty() ? null : guardedNotificationAction(activity,
            () -> activity.startActivity(MainActivity.moduleIntent(activity, moduleId)));
    }

    private Runnable guardedNotificationAction(Activity activity, Runnable action) {
        return () -> {
            if (!isAdded() || binding == null || activity == null
                || activity.isFinishing() || activity.isDestroyed()) {
                return;
            }
            if (!getViewLifecycleOwner().getLifecycle().getCurrentState()
                .isAtLeast(Lifecycle.State.STARTED)) {
                return;
            }
            try {
                action.run();
            } catch (RuntimeException | LinkageError exception) {
                CrashReporter.record("打开通知相关页面", exception);
                if (binding != null && binding.getRoot().isAttachedToWindow()) {
                    Snackbar.make(binding.getRoot(), "相关页面暂时无法打开，请稍后重试", Snackbar.LENGTH_LONG).show();
                }
            }
        };
    }

    private static String notificationActionLabel(JsonObject item, JsonObject payload) {
        String group = Jsons.string(item, "group_key");
        if (firstPositive(payload, "post_id", "forum_post_id", "thread_id", "target_post_id") > 0) {
            return firstPositive(payload, "comment_id", "forum_comment_id", "reply_id", "target_comment_id") > 0
                ? "查看相关评论" : "查看帖子";
        }
        if ("bounties".equals(group)) return "查看悬赏";
        if ("resources".equals(group)) return "查看资源";
        if ("orders".equals(group)) return "查看订单";
        if ("lottery".equals(group)) return "查看抽奖活动";
        if ("activities".equals(group)) return "查看活动";
        if ("wallet".equals(group)) return "查看资金来往明细";
        if ("groups".equals(group)) return "查看群聊";
        if ("rooms".equals(group)) return "查看聊天室";
        if ("forums".equals(group) || "comments".equals(group)) return "查看帖子中心";
        if ("content".equals(group)) return "查看内容";
        if (firstPositive(payload, "user_id", "from_user_id", "actor_user_id", "follower_user_id") > 0) {
            return "查看用户资料";
        }
        return "查看相关内容";
    }

    private static long firstPositive(JsonObject object, String... keys) {
        for (String key : keys) {
            long value = Jsons.longValue(object, key);
            if (value > 0) return value;
        }
        return 0;
    }

    private static long relatedRecordId(String group, JsonObject payload) {
        if ("bounties".equals(group)) return firstPositive(payload, "bounty_id", "submission_id", "target_id");
        if ("resources".equals(group)) return firstPositive(payload, "resource_id", "target_id");
        if ("orders".equals(group)) return firstPositive(payload, "order_id", "purchase_id", "target_id");
        if ("lottery".equals(group)) return firstPositive(payload, "lottery_id", "draw_id", "activity_id", "target_id");
        if ("activities".equals(group)) {
            return firstPositive(payload, "activity_id", "red_packet_id", "packet_id", "poll_id", "vote_id", "gift_id", "target_id");
        }
        if ("groups".equals(group)) return firstPositive(payload, "group_id", "chat_group_id", "target_id");
        if ("rooms".equals(group)) return firstPositive(payload, "room_id", "chat_room_id", "target_id");
        if ("content".equals(group)) return firstPositive(payload, "document_id", "note_id", "file_id", "target_id");
        return 0;
    }

    private static String notificationSource(JsonObject item, JsonObject payload) {
        return (Jsons.string(item, "notification_type") + " "
            + Jsons.string(item, "source_type") + " "
            + Jsons.string(item, "title") + " "
            + Jsons.string(item, "message") + " "
            + Jsons.string(payload, "notification_type") + " "
            + Jsons.string(payload, "type") + " "
            + Jsons.string(payload, "scene") + " "
            + Jsons.string(payload, "module") + " "
            + Jsons.string(payload, "target_type")).toLowerCase(java.util.Locale.ROOT);
    }

    static boolean isLifecycleNotification(JsonObject item, JsonObject payload) {
        String source = notificationSource(
            item == null ? new JsonObject() : item,
            payload == null ? new JsonObject() : payload
        );
        return containsAny(source,
            "maintenance", "software_update", "app_update", "version_update",
            "release_update", "force_update", "upgrade",
            "系统维护", "维护通知", "版本更新", "软件更新", "强制更新");
    }

    private static boolean containsAny(String source, String... values) {
        for (String value : values) if (source.contains(value)) return true;
        return false;
    }

    private static String walletCategory(String source) {
        if (containsAny(source, "red_packet", "redpacket", "红包")) return "red_packet";
        if (containsAny(source, "transfer", "转账")) return "transfer";
        if (containsAny(source, "gift", "bounty", "reward_post", "打赏", "礼物")) return "gift";
        if (containsAny(source, "order", "purchase", "sale", "refund", "shop", "store", "购买", "退款")) return "shopping";
        if (containsAny(source, "sign", "invite", "experience", "register", "签到", "邀请", "奖励")) return "reward";
        if (containsAny(source, "recharge", "withdraw", "充值", "提现")) return "recharge_withdrawal";
        if (containsAny(source, "admin", "adjust", "管理调整")) return "admin";
        return "all";
    }

    private static String relatedModule(String group) {
        if ("forums".equals(group)) return "forum_posts";
        if ("bounties".equals(group)) return "bounties";
        if ("resources".equals(group)) return "resources";
        if ("orders".equals(group)) return "orders";
        if ("lottery".equals(group)) return "lottery";
        if ("activities".equals(group)) return "hierarchy_activities";
        if ("wallet".equals(group)) return "wallet_logs";
        if ("groups".equals(group) || "rooms".equals(group)) return "chat_rooms";
        if ("social".equals(group)) return "friends";
        if ("content".equals(group)) return "documents";
        return "";
    }

    private static String notificationKey(JsonObject item) {
        String sourceType = Jsons.string(item, "source_type");
        long sourceId = Jsons.longValue(item, "source_id");
        if (sourceId > 0) return sourceType + ":" + sourceId;
        return sourceType + ":" + Jsons.string(item, "notification_type")
            + ":" + Jsons.string(item, "created_at")
            + ":" + Jsons.string(item, "title");
    }

    private void readOne(JsonObject item) {
        if (actionRequest != null) return;
        String sourceType = Jsons.string(item, "source_type");
        long sourceId = Jsons.longValue(item, "source_id");
        String groupKey = Jsons.string(item, "group_key");
        if (sourceType.isEmpty() || sourceId <= 0) return;
        JsonObject body = new JsonObject();
        body.addProperty("source_type", sourceType);
        actionRequest = app().repository().post(
            "/api/user/notifications/" + sourceId + "/read", body, result -> {
                actionRequest = null;
                if (binding == null) return;
                if (handleFailure(result, binding.getRoot())) return;
                boolean changed = markNotificationRead(sourceType, sourceId);
                if (changed) {
                    decrementGroup(groupKey);
                    decrementCenter(1);
                }
                render(currentUnread());
            });
    }

    private boolean markNotificationRead(String sourceType, long sourceId) {
        for (JsonObject notification : notifications) {
            if (!sourceType.equals(Jsons.string(notification, "source_type"))
                || sourceId != Jsons.longValue(notification, "source_id")) {
                continue;
            }
            if (booleanValue(notification, "is_read")) return false;
            notification.addProperty("is_read", true);
            return true;
        }
        return false;
    }

    private void readGroup(JsonObject group) {
        if (actionRequest != null) return;
        String key = Jsons.string(group, "key");
        int unreadBefore = Jsons.intValue(group, "unread_count", 0);
        actionRequest = app().repository().post(
            "/api/user/notifications/groups/" + key + "/read", new JsonObject(), result -> {
                actionRequest = null;
                if (binding == null) return;
                if (handleFailure(result, binding.getRoot())) return;
                for (JsonObject item : notifications) {
                    if (key.equals(Jsons.string(item, "group_key"))) item.addProperty("is_read", true);
                }
                setGroupUnread(key, 0);
                decrementCenter(unreadBefore);
                render(currentUnread());
                Snackbar.make(binding.getRoot(), result.message(), Snackbar.LENGTH_SHORT).show();
            });
    }

    private void readAll() {
        if (actionRequest != null || currentUnread() == 0) return;
        JsonObject body = new JsonObject();
        body.addProperty("center", selectedCenter);
        actionRequest = app().repository().post("/api/user/notifications/read-all", body, result -> {
            actionRequest = null;
            if (binding == null) return;
            if (handleFailure(result, binding.getRoot())) return;
            for (JsonObject item : notifications) item.addProperty("is_read", true);
            for (JsonObject group : groups) group.addProperty("unread_count", 0);
            setCenterUnread(selectedCenter, 0);
            render(0);
            Snackbar.make(binding.getRoot(), centerName(selectedCenter) + "已全部标记为已读", Snackbar.LENGTH_SHORT).show();
        });
    }

    private void decrementGroup(String key) {
        for (JsonObject group : groups) {
            if (!key.equals(Jsons.string(group, "key"))) continue;
            group.addProperty("unread_count", Math.max(0, Jsons.intValue(group, "unread_count", 0) - 1));
            return;
        }
    }

    private void setGroupUnread(String key, int value) {
        for (JsonObject group : groups) {
            if (key.equals(Jsons.string(group, "key"))) group.addProperty("unread_count", value);
        }
    }

    private int currentUnread() {
        int total = 0;
        for (JsonObject group : groups) total += Jsons.intValue(group, "unread_count", 0);
        return total;
    }

    private void renderCenterTabs() {
        if (binding == null) return;
        binding.socialCenter.setText(centerTabText("动态", centerUnread("social")));
        binding.activityCenter.setText(centerTabText("活动", centerUnread("activity")));
        binding.systemCenter.setText(centerTabText("系统", centerUnread("system")));
        int buttonId = centerButtonId(selectedCenter);
        if (binding.centerTabs.getCheckedButtonId() != buttonId) {
            changingCenter = true;
            binding.centerTabs.check(buttonId);
            changingCenter = false;
        }
    }

    private int centerUnread(String center) {
        for (JsonObject item : centers) {
            if (center.equals(Jsons.string(item, "key"))) return Jsons.intValue(item, "unread_count", 0);
        }
        return center.equals(selectedCenter) ? currentUnread() : 0;
    }

    private void decrementCenter(int count) {
        setCenterUnread(selectedCenter, Math.max(0, centerUnread(selectedCenter) - Math.max(0, count)));
    }

    private void setCenterUnread(String center, int value) {
        for (JsonObject item : centers) {
            if (center.equals(Jsons.string(item, "key"))) {
                item.addProperty("unread_count", Math.max(0, value));
                return;
            }
        }
    }

    private static String centerTabText(String label, int unread) {
        return unread > 0 ? label + " " + unread : label;
    }

    private static String normalizeCenter(String center) {
        if ("activity".equals(center) || "system".equals(center)) return center;
        return "social";
    }

    private static String centerName(String center) {
        if ("activity".equals(center)) return "活动通知";
        if ("system".equals(center)) return "系统通知";
        return "动态通知";
    }

    private static int centerButtonId(String center) {
        if ("activity".equals(center)) return R.id.activityCenter;
        if ("system".equals(center)) return R.id.systemCenter;
        return R.id.socialCenter;
    }

    private static String centerFromButton(int buttonId) {
        if (buttonId == R.id.activityCenter) return "activity";
        if (buttonId == R.id.systemCenter) return "system";
        return "social";
    }

    private static boolean booleanValue(JsonObject object, String key) {
        try { return object.has(key) && !object.get(key).isJsonNull() && object.get(key).getAsBoolean(); }
        catch (RuntimeException ignored) { return false; }
    }

    @Override public void onDestroyView() {
        if (request != null) request.cancel();
        if (actionRequest != null) actionRequest.cancel();
        openedNotificationKey = "";
        lastNotificationClickAt = 0L;
        binding = null;
        super.onDestroyView();
    }
}
