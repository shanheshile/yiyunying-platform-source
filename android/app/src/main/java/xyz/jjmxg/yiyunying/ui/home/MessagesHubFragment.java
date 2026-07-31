package xyz.jjmxg.yiyunying.ui.home;

import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;

import androidx.annotation.NonNull;
import androidx.annotation.Nullable;
import androidx.fragment.app.Fragment;
import androidx.recyclerview.widget.LinearLayoutManager;

import com.google.gson.JsonArray;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;
import xyz.jjmxg.yiyunying.ui.common.YiyunyingDialogBuilder;
import com.google.android.material.snackbar.Snackbar;

import java.util.ArrayList;
import java.util.Collections;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Locale;
import java.util.Map;
import android.content.SharedPreferences;

import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.data.api.RequestHandle;
import xyz.jjmxg.yiyunying.databinding.FragmentMessagesHubBinding;
import xyz.jjmxg.yiyunying.ui.chat.ChatActivity;
import xyz.jjmxg.yiyunying.ui.common.BaseFragment;
import xyz.jjmxg.yiyunying.ui.common.GlassActionDialog;
import xyz.jjmxg.yiyunying.ui.social.AddFriendActivity;
import xyz.jjmxg.yiyunying.ui.social.SocialDirectoryActivity;
import xyz.jjmxg.yiyunying.R;

public final class MessagesHubFragment extends BaseFragment implements UserTabPage {
    private FragmentMessagesHubBinding binding;
    private MessageCenterAdapter adapter;
    private final List<JsonObject> allItems = new ArrayList<>();
    private final Handler handler = new Handler(Looper.getMainLooper());
    private RequestHandle request;
    private RequestHandle cacheRequest;
    private RequestHandle preferenceRequest;
    private long requestGeneration;
    private String query = "";
    private long intervalMs = 10000L;
    private boolean running;
    private boolean pinnedCollapsed;
    private boolean bottomedCollapsed;
    private int hiddenCount;
    private SharedPreferences draftPreferences;
    private String serverItemsSnapshot = "";
    private String draftStateSnapshot = "";
    private String relationshipStateSnapshot = "";
    private final Runnable poll = () -> load(false);

    public static MessagesHubFragment newInstance() { return new MessagesHubFragment(); }

    @Nullable @Override
    public View onCreateView(@NonNull LayoutInflater inflater, @Nullable ViewGroup container, @Nullable Bundle state) {
        binding = FragmentMessagesHubBinding.inflate(inflater, container, false);
        pinnedCollapsed = requireContext().getSharedPreferences("message_center", 0)
            .getBoolean("pinned_collapsed", false);
        bottomedCollapsed = requireContext().getSharedPreferences("message_center", 0)
            .getBoolean("bottomed_collapsed", true);
        draftPreferences = requireContext().getSharedPreferences("composer_drafts", 0);
        adapter = new MessageCenterAdapter(new MessageCenterAdapter.Listener() {
            @Override public void onClick(JsonObject item) { openChat(item); }
            @Override public void onLongPress(JsonObject item) { showConversationActions(item); }
            @Override public void onSectionClick(JsonObject section) { toggleSection(Jsons.string(section, "section_key")); }
        });
        binding.recycler.setLayoutManager(new LinearLayoutManager(requireContext()));
        binding.recycler.setHasFixedSize(true);
        binding.recycler.setItemViewCacheSize(6);
        binding.recycler.setItemAnimator(null);
        binding.recycler.setAdapter(adapter);
        binding.swipeRefresh.setOnRefreshListener(() -> load(false));
        binding.friendIncoming.setOnClickListener(view -> RelationshipNoticeActivity.open(requireContext(), "friend_incoming", "好友申请"));
        binding.friendOutgoing.setOnClickListener(view -> RelationshipNoticeActivity.open(requireContext(), "friend_outgoing", "申请加好友"));
        binding.friendFiltered.setOnClickListener(view -> RelationshipNoticeActivity.open(requireContext(), "friend_filtered", "好友过滤通知"));
        binding.groupJoinRequests.setOnClickListener(view -> RelationshipNoticeActivity.open(requireContext(), "group_join", "群聊申请"));
        binding.groupInvitations.setOnClickListener(view -> RelationshipNoticeActivity.open(requireContext(), "group_invitation", "邀请加入"));
        binding.groupFiltered.setOnClickListener(view -> RelationshipNoticeActivity.open(requireContext(), "group_filtered", "群聊过滤通知"));
        binding.hiddenConversations.setOnClickListener(view -> HiddenConversationsActivity.open(requireContext()));
        binding.friendDirectoryButton.setOnClickListener(view -> SocialDirectoryActivity.openFriends(requireContext()));
        binding.groupDirectoryButton.setOnClickListener(view -> SocialDirectoryActivity.openRooms(requireContext()));
        binding.relationshipNoticeEntry.setOnClickListener(view -> RelationshipHubActivity.open(requireContext()));
        binding.retryButton.setOnClickListener(view -> load(true));
        // The fragment instance may retain its data while Android recreates only the view.
        // Re-submit that data to the new adapter immediately instead of waiting for a changed poll response.
        filter();
        return binding.getRoot();
    }

    @Override public void onStart() {
        super.onStart();
        running = true;
        load(allItems.isEmpty());
    }

    @Override public void onStop() {
        running = false;
        handler.removeCallbacks(poll);
        requestGeneration++;
        if (request != null) request.cancel();
        request = null;
        if (binding != null) binding.swipeRefresh.setRefreshing(false);
        super.onStop();
    }

    private void load(boolean full) {
        if (binding == null || request != null) return;
        handler.removeCallbacks(poll);
        if (full) binding.progress.setVisibility(View.VISIBLE);
        LinkedHashMap<String, String> params = new LinkedHashMap<>();
        params.put("limit", "200");
        final long generation = ++requestGeneration;
        if (full && allItems.isEmpty()) {
            if (cacheRequest != null) cacheRequest.cancel();
            cacheRequest = app().repository().getCached("/api/user/message-center", params, cached -> {
                cacheRequest = null;
                if (generation != requestGeneration || binding == null || !cached.isSuccessful()) return;
                renderMessageCenter(cached, false);
            });
        }
        request = app().repository().get("/api/user/message-center", params, result -> {
            if (generation != requestGeneration) return;
            request = null;
            if (binding == null) return;
            binding.progress.setVisibility(View.GONE);
            binding.swipeRefresh.setRefreshing(false);
            if (handleFailure(result, binding.getRoot())) {
                binding.errorState.setVisibility(allItems.isEmpty() ? View.VISIBLE : View.GONE);
            } else {
                renderMessageCenter(result, true);
            }
            if (running) handler.postDelayed(poll, intervalMs);
        });
    }

    private void renderMessageCenter(xyz.jjmxg.yiyunying.data.api.ApiResult result, boolean live) {
        if (binding == null || !result.isSuccessful()) return;
        binding.errorState.setVisibility(View.GONE);
        binding.progress.setVisibility(View.GONE);
        JsonArray items = result.items();
        String nextServerItemsSnapshot = items.toString();
        String nextDraftStateSnapshot = createDraftStateSnapshot();
        boolean serverChanged = !nextServerItemsSnapshot.equals(serverItemsSnapshot);
        boolean draftsChanged = !nextDraftStateSnapshot.equals(draftStateSnapshot);
        if (serverChanged) {
            allItems.clear();
            for (JsonElement element : items) {
                if (element.isJsonObject()) allItems.add(element.getAsJsonObject());
            }
            serverItemsSnapshot = nextServerItemsSnapshot;
        }
        draftStateSnapshot = nextDraftStateSnapshot;
        if (serverChanged || draftsChanged || adapter.getItemCount() == 0) filter();
        if (live) {
            long configured = Jsons.longValue(result.dataObject(), "poll_interval_ms");
            if (configured > 0L) intervalMs = Math.max(5000L, Math.min(60000L, configured));
        }
        JsonObject relationshipSummary = Jsons.object(result.dataObject(), "relationship_summary");
        int nextHiddenCount = Jsons.intValue(result.dataObject(), "hidden_count", 0);
        String nextRelationshipSnapshot = relationshipSummary.toString() + "|" + nextHiddenCount;
        if (!nextRelationshipSnapshot.equals(relationshipStateSnapshot)) {
            relationshipStateSnapshot = nextRelationshipSnapshot;
            renderRelationshipSummary(relationshipSummary, nextHiddenCount);
        }
        Fragment parent = getParentFragment();
        if (parent instanceof UserShellFragment) {
            ((UserShellFragment) parent).setUnreadCount(Jsons.intValue(result.dataObject(), "unread_count", 0));
            ((UserShellFragment) parent).setNotificationUnreadCount(
                Jsons.intValue(result.dataObject(), "notification_unread_count", 0));
        }
    }

    private void renderRelationshipSummary(JsonObject summary, int hiddenCount) {
        if (binding == null) return;
        this.hiddenCount = Math.max(0, hiddenCount);
        JsonObject friend = Jsons.object(summary, "friend");
        JsonObject group = Jsons.object(summary, "group");
        int friendPending = Jsons.intValue(friend, "incoming_count", 0);
        int groupPending = Jsons.intValue(group, "join_count", 0) + Jsons.intValue(group, "invitation_count", 0);
        binding.friendNoticeLatest.setText(valueOr(Jsons.string(friend, "latest_text"), "暂无好友通知"));
        binding.groupNoticeLatest.setText(valueOr(Jsons.string(group, "latest_text"), "暂无群聊通知"));
        showBadge(binding.friendNoticeBadge, friendPending);
        showBadge(binding.groupNoticeBadge, groupPending);
        showBadge(binding.relationshipNoticeBadge, friendPending + groupPending);
        String friendLatest = valueOr(Jsons.string(friend, "latest_text"), "暂无好友通知");
        String groupLatest = valueOr(Jsons.string(group, "latest_text"), "暂无群聊通知");
        binding.relationshipNoticeLatest.setText(friendPending > 0 ? friendLatest : groupLatest);
        binding.friendIncoming.setText("好友申请 " + Jsons.intValue(friend, "incoming_count", 0));
        binding.friendOutgoing.setText("申请加好友 " + Jsons.intValue(friend, "outgoing_count", 0));
        binding.friendFiltered.setText("过滤通知 " + Jsons.intValue(friend, "filtered_count", 0));
        binding.groupJoinRequests.setText("群聊申请 " + Jsons.intValue(group, "join_count", 0));
        binding.groupInvitations.setText("邀请加入 " + Jsons.intValue(group, "invitation_count", 0));
        binding.groupFiltered.setText("过滤通知 " + Jsons.intValue(group, "filtered_count", 0));
        renderHiddenCount();
    }

    private static void showBadge(android.widget.TextView badge, int count) {
        badge.setVisibility(count > 0 ? View.VISIBLE : View.GONE);
        badge.setText(String.valueOf(Math.min(99, count)));
    }

    private static String valueOr(String value, String fallback) {
        return value == null || value.trim().isEmpty() ? fallback : value;
    }

    private void filter() {
        if (binding == null) return;
        String needle = query.trim().toLowerCase(Locale.ROOT);
        List<JsonObject> pinned = new ArrayList<>();
        List<JsonObject> bottomed = new ArrayList<>();
        List<JsonObject> regular = new ArrayList<>();
        for (JsonObject item : allItems) {
            if (boolValue(item, "is_hidden")) continue;
            applyLocalDraft(item);
            String haystack = (Jsons.string(item, "title") + " " + Jsons.string(item, "account")
                + " " + Jsons.string(item, "uid") + " " + Jsons.string(item, "public_no")
                + " " + Jsons.string(item, "peer_uid") + " " + Jsons.string(item, "peer_account")
                + " " + Jsons.string(item, "name") + " " + Jsons.longValue(item, "target_id")
                + " " + Jsons.string(item, "last_message") + " " + Jsons.string(item, "draft_content"))
                .toLowerCase(Locale.ROOT);
            if (needle.isEmpty() || haystack.contains(needle)) {
                if (boolValue(item, "is_pinned")) pinned.add(item);
                else if (boolValue(item, "is_bottomed")) bottomed.add(item);
                else regular.add(item);
            }
        }
        regular.sort((left, right) -> {
            int draft = Boolean.compare(boolValue(right, "has_draft"), boolValue(left, "has_draft"));
            if (draft != 0) return draft;
            if (boolValue(left, "has_draft")) {
                return Long.compare(Jsons.longValue(right, "draft_updated_at_epoch"),
                    Jsons.longValue(left, "draft_updated_at_epoch"));
            }
            return 0;
        });
        List<JsonObject> display = new ArrayList<>();
        if (!pinned.isEmpty()) {
            JsonObject section = new JsonObject();
            section.addProperty("type", "section");
            section.addProperty("section_key", "pinned");
            section.addProperty("title", "置顶会话（" + pinned.size() + "）");
            section.addProperty("collapsed", pinnedCollapsed);
            display.add(section);
            if (!pinnedCollapsed) display.addAll(pinned);
        }
        display.addAll(regular);
        if (!bottomed.isEmpty()) {
            JsonObject section = new JsonObject();
            section.addProperty("type", "section");
            section.addProperty("section_key", "bottomed");
            section.addProperty("title", "置底会话（" + bottomed.size() + "）· 不参与新消息排序");
            section.addProperty("collapsed", bottomedCollapsed);
            display.add(section);
            if (!bottomedCollapsed) display.addAll(bottomed);
        }
        adapter.submit(display);
        binding.emptyState.setVisibility(pinned.isEmpty() && regular.isEmpty() && bottomed.isEmpty() ? View.VISIBLE : View.GONE);
    }

    private void applyLocalDraft(JsonObject item) {
        String key;
        switch (Jsons.string(item, "type")) {
            case "private":
                key = "conversation:" + Jsons.longValue(item, "target_id") + ":"
                    + Jsons.longValue(item, "peer_user_id");
                break;
            case "group":
                key = "room:" + Jsons.longValue(item, "target_id") + ":0";
                break;
            case "service":
                key = "service_user:0:0";
                break;
            default:
                return;
        }
        SharedPreferences drafts = draftPreferences;
        if (drafts == null || !drafts.contains(key)) {
            item.addProperty("has_draft", false);
            item.addProperty("draft_content", "");
            item.addProperty("draft_updated_at_epoch", 0L);
            return;
        }
        String content = drafts.getString(key, "");
        boolean hasDraft = content != null && !content.trim().isEmpty();
        item.addProperty("has_draft", hasDraft);
        item.addProperty("draft_content", hasDraft ? content : "");
        item.addProperty("draft_updated_at_epoch", hasDraft ? drafts.getLong("draft_time:" + key, 0L) : 0L);
    }

    private String createDraftStateSnapshot() {
        if (draftPreferences == null) return "";
        Map<String, ?> values = draftPreferences.getAll();
        List<String> keys = new ArrayList<>(values.keySet());
        Collections.sort(keys);
        StringBuilder snapshot = new StringBuilder(keys.size() * 32);
        for (String key : keys) {
            if (!key.startsWith("conversation:") && !key.startsWith("room:")
                && !key.startsWith("service_user:") && !key.startsWith("draft_time:")) continue;
            snapshot.append(key).append('=').append(String.valueOf(values.get(key))).append('\n');
        }
        return snapshot.toString();
    }

    private void openChat(JsonObject item) {
        String type = Jsons.string(item, "type");
        if ("group".equals(type)) {
            ChatActivity.openRoom(requireContext(), Jsons.longValue(item, "target_id"), Jsons.string(item, "title"));
        } else if ("private".equals(type)) {
            ChatActivity.openConversation(requireContext(), Jsons.longValue(item, "target_id"),
                Jsons.longValue(item, "peer_user_id"), Jsons.string(item, "title"));
        } else if ("service".equals(type)) {
            ChatActivity.openUserService(requireContext());
        } else if ("bot".equals(type)) {
            host().openModule("bot");
        }
    }

    private void toggleSection(String key) {
        if ("bottomed".equals(key)) bottomedCollapsed = !bottomedCollapsed;
        else pinnedCollapsed = !pinnedCollapsed;
        requireContext().getSharedPreferences("message_center", 0).edit()
            .putBoolean("pinned_collapsed", pinnedCollapsed)
            .putBoolean("bottomed_collapsed", bottomedCollapsed).apply();
        filter();
    }

    private void showConversationActions(JsonObject item) {
        boolean pinned = boolValue(item, "is_pinned");
        boolean bottomed = boolValue(item, "is_bottomed");
        boolean muted = boolValue(item, "is_muted");
        List<GlassActionDialog.Action> actions = new ArrayList<>();
        actions.add(new GlassActionDialog.Action(pinned ? "取消置顶" : "置顶", R.drawable.ic_content,
            () -> savePreference(item, "is_pinned", !pinned)));
        actions.add(new GlassActionDialog.Action(bottomed ? "取消置底" : "置底", R.drawable.ic_chevron_right,
            () -> savePreference(item, "is_bottomed", !bottomed)));
        actions.add(new GlassActionDialog.Action(muted ? "开通知" : "免打扰", R.drawable.ic_chat,
            () -> savePreference(item, "is_muted", !muted)));
        actions.add(new GlassActionDialog.Action("隐藏", R.drawable.ic_more, () ->
            new YiyunyingDialogBuilder(requireContext())
                .setTitle("隐藏会话")
                .setMessage("只从当前账号的消息列表隐藏，不删除云端聊天记录。")
                .setPositiveButton("确认隐藏", (confirm, button) -> savePreference(item, "is_hidden", true))
                .setNegativeButton("取消", null).show()));
        GlassActionDialog.showCompact(requireContext(), actions);
    }

    private void savePreference(JsonObject item, String field, boolean value) {
        if (preferenceRequest != null) return;
        JsonObject body = new JsonObject();
        body.addProperty(field, value);
        String path = "/api/user/message-center/" + Jsons.string(item, "type") + "/"
            + Jsons.longValue(item, "target_id") + "/preference";
        preferenceRequest = app().repository().put(path, body, result -> {
            preferenceRequest = null;
            if (binding == null) return;
            if (!result.isSuccessful()) {
                Snackbar.make(binding.getRoot(), result.message().isEmpty() ? "会话设置失败" : result.message(), Snackbar.LENGTH_LONG).show();
                return;
            }
            JsonObject updated = result.dataObject();
            for (String key : new String[]{"is_pinned", "is_bottomed", "is_muted", "is_hidden"}) {
                if (updated.has(key) && !updated.get(key).isJsonNull()) item.add(key, updated.get(key).deepCopy());
            }
            if ("is_hidden".equals(field)) {
                hiddenCount = Math.max(0, hiddenCount + (value ? 1 : -1));
                renderHiddenCount();
            }
            filter();
            Snackbar.make(binding.getRoot(), result.message().isEmpty() ? "会话设置已保存" : result.message(), Snackbar.LENGTH_SHORT).show();
        });
    }

    private static boolean boolValue(JsonObject object, String key) {
        try { return object.has(key) && !object.get(key).isJsonNull() && object.get(key).getAsBoolean(); }
        catch (RuntimeException ignored) { return false; }
    }

    private void renderHiddenCount() {
        if (binding != null) binding.hiddenConversations.setText("隐藏会话（" + hiddenCount + "）");
    }

    @Override public void onSearchQuery(String value) {
        query = value == null ? "" : value;
        filter();
    }

    @Override public void onPrimaryAction() {
        new YiyunyingDialogBuilder(requireContext())
            .setTitle("新建会话")
            .setItems(new String[]{"添加好友", "新建群聊", "新建聊天室"}, (dialog, which) -> {
                if (which == 0) AddFriendActivity.open(requireContext());
                else if (which == 1) SocialDirectoryActivity.openCreateGroup(requireContext());
                else SocialDirectoryActivity.openCreateChatroom(requireContext());
            })
            .setNegativeButton("取消", null)
            .show();
    }

    @Override public void onDestroyView() {
        handler.removeCallbacks(poll);
        requestGeneration++;
        if (request != null) request.cancel();
        request = null;
        if (cacheRequest != null) cacheRequest.cancel();
        cacheRequest = null;
        if (preferenceRequest != null) preferenceRequest.cancel();
        preferenceRequest = null;
        draftPreferences = null;
        binding = null;
        super.onDestroyView();
    }
}
