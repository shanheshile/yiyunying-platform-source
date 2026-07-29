package xyz.jjmxg.yiyunying.ui.home;

import android.content.Context;
import android.content.Intent;
import android.os.Bundle;
import android.view.View;

import com.google.gson.JsonObject;

import java.util.LinkedHashMap;

import xyz.jjmxg.yiyunying.core.AppAccess;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.data.api.RequestHandle;
import xyz.jjmxg.yiyunying.databinding.ActivityRelationshipHubBinding;
import xyz.jjmxg.yiyunying.ui.common.SystemInsetActivity;

public final class RelationshipHubActivity extends SystemInsetActivity {
    private ActivityRelationshipHubBinding binding;
    private RequestHandle request;

    public static void open(Context context) {
        context.startActivity(new Intent(context, RelationshipHubActivity.class));
    }

    @Override protected void onCreate(Bundle state) {
        super.onCreate(state);
        binding = ActivityRelationshipHubBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());
        binding.toolbar.setNavigationOnClickListener(view -> finish());
        binding.friendIncoming.setOnClickListener(view -> openNotice("friend_incoming", "好友申请"));
        binding.friendOutgoing.setOnClickListener(view -> openNotice("friend_outgoing", "申请加好友"));
        binding.friendFiltered.setOnClickListener(view -> openNotice("friend_filtered", "好友过滤通知"));
        binding.groupJoin.setOnClickListener(view -> openNotice("group_join", "群聊申请"));
        binding.groupInvitation.setOnClickListener(view -> openNotice("group_invitation", "邀请加入"));
        binding.groupFiltered.setOnClickListener(view -> openNotice("group_filtered", "群聊过滤通知"));
    }

    @Override protected void onResume() {
        super.onResume();
        load();
    }

    private void openNotice(String filter, String title) {
        RelationshipNoticeActivity.open(this, filter, title);
    }

    private void load() {
        if (request != null) return;
        binding.progress.setVisibility(View.VISIBLE);
        LinkedHashMap<String, String> query = new LinkedHashMap<>();
        query.put("limit", "1");
        request = AppAccess.from(this).repository().get("/api/user/message-center", query, result -> {
            request = null;
            if (binding == null) return;
            binding.progress.setVisibility(View.INVISIBLE);
            if (!result.isSuccessful()) return;
            JsonObject summary = Jsons.object(result.dataObject(), "relationship_summary");
            JsonObject friend = Jsons.object(summary, "friend");
            JsonObject group = Jsons.object(summary, "group");
            int friendPending = Jsons.intValue(friend, "incoming_count", 0);
            int groupPending = Jsons.intValue(group, "join_count", 0)
                + Jsons.intValue(group, "invitation_count", 0);
            showBadge(binding.friendBadge, friendPending);
            showBadge(binding.groupBadge, groupPending);
            binding.friendLatest.setText(value(Jsons.string(friend, "latest_text"), "暂无好友通知"));
            binding.groupLatest.setText(value(Jsons.string(group, "latest_text"), "暂无群聊通知"));
            binding.friendIncoming.setText("好友申请 " + Jsons.intValue(friend, "incoming_count", 0));
            binding.friendOutgoing.setText("申请加好友 " + Jsons.intValue(friend, "outgoing_count", 0));
            binding.friendFiltered.setText("过滤通知 " + Jsons.intValue(friend, "filtered_count", 0));
            binding.groupJoin.setText("群聊申请 " + Jsons.intValue(group, "join_count", 0));
            binding.groupInvitation.setText("邀请加入 " + Jsons.intValue(group, "invitation_count", 0));
            binding.groupFiltered.setText("过滤通知 " + Jsons.intValue(group, "filtered_count", 0));
        });
    }

    private static void showBadge(android.widget.TextView badge, int count) {
        badge.setVisibility(count > 0 ? View.VISIBLE : View.GONE);
        badge.setText(count > 99 ? "99+" : String.valueOf(count));
    }

    private static String value(String text, String fallback) {
        return text == null || text.trim().isEmpty() ? fallback : text;
    }

    @Override protected void onDestroy() {
        if (request != null) request.cancel();
        binding = null;
        super.onDestroy();
    }
}
