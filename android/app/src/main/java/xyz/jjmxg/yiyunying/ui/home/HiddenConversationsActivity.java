package xyz.jjmxg.yiyunying.ui.home;

import android.content.Context;
import android.content.Intent;
import android.os.Bundle;
import android.view.View;

import androidx.recyclerview.widget.LinearLayoutManager;

import xyz.jjmxg.yiyunying.ui.common.YiyunyingDialogBuilder;
import com.google.android.material.snackbar.Snackbar;
import com.google.gson.JsonObject;

import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;

import xyz.jjmxg.yiyunying.core.AppAccess;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.data.api.RequestHandle;
import xyz.jjmxg.yiyunying.databinding.ActivityRelationshipNoticeBinding;
import xyz.jjmxg.yiyunying.ui.chat.ChatActivity;
import xyz.jjmxg.yiyunying.ui.common.SystemInsetActivity;

public final class HiddenConversationsActivity extends SystemInsetActivity {
    private ActivityRelationshipNoticeBinding binding;
    private final List<JsonObject> items = new ArrayList<>();
    private MessageCenterAdapter adapter;
    private RequestHandle request;
    private RequestHandle cacheRequest;
    private RequestHandle actionRequest;

    public static void open(Context context) {
        context.startActivity(new Intent(context, HiddenConversationsActivity.class));
    }

    @Override protected void onCreate(Bundle state) {
        super.onCreate(state);
        binding = ActivityRelationshipNoticeBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());
        binding.toolbar.setTitle("隐藏会话");
        binding.toolbar.setNavigationOnClickListener(view -> finish());
        binding.policyNotice.setText("隐藏只影响当前账号的消息列表，不会删除云端聊天记录。点击会话可打开或取消隐藏。 ");
        adapter = new MessageCenterAdapter(new MessageCenterAdapter.Listener() {
            @Override public void onClick(JsonObject item) { showActions(item); }
            @Override public void onLongPress(JsonObject item) { showActions(item); }
            @Override public void onSectionClick(JsonObject section) { }
        });
        binding.recycler.setLayoutManager(new LinearLayoutManager(this));
        binding.recycler.setAdapter(adapter);
        binding.swipeRefresh.setOnRefreshListener(this::load);
        load();
    }

    private void load() {
        if (request != null) request.cancel();
        binding.progress.setVisibility(View.VISIBLE);
        LinkedHashMap<String, String> query = new LinkedHashMap<>();
        query.put("include_hidden", "1");
        query.put("limit", "200");
        if (items.isEmpty()) {
            if (cacheRequest != null) cacheRequest.cancel();
            cacheRequest = AppAccess.from(this).repository().getCached("/api/user/message-center", query, cached -> {
                cacheRequest = null;
                if (binding == null || !cached.isSuccessful()) return;
                renderItems(cached.objectItems());
                binding.progress.setVisibility(View.INVISIBLE);
            });
        }
        request = AppAccess.from(this).repository().get("/api/user/message-center", query, result -> {
            request = null;
            if (binding == null) return;
            binding.progress.setVisibility(View.INVISIBLE);
            binding.swipeRefresh.setRefreshing(false);
            if (!result.isSuccessful()) {
                Snackbar.make(binding.getRoot(), result.message().isEmpty() ? "隐藏会话加载失败" : result.message(), Snackbar.LENGTH_LONG).show();
                return;
            }
            renderItems(result.objectItems());
        });
    }

    private void renderItems(List<JsonObject> source) {
        if (binding == null) return;
        items.clear();
        for (JsonObject item : source) if (bool(item, "is_hidden")) items.add(item);
        adapter.submit(items);
        binding.emptyText.setText("没有隐藏的会话");
        binding.emptyText.setVisibility(items.isEmpty() ? View.VISIBLE : View.GONE);
    }

    private void showActions(JsonObject item) {
        new YiyunyingDialogBuilder(this)
            .setBusinessTitle(Jsons.string(item, "title"))
            .setItems(new String[]{"打开会话", "取消隐藏"}, (dialog, which) -> {
                if (which == 0) openChat(item);
                else unhide(item);
            })
            .setNegativeButton("取消", null)
            .show();
    }

    private void openChat(JsonObject item) {
        String type = Jsons.string(item, "type");
        if ("group".equals(type)) ChatActivity.openRoom(this, Jsons.longValue(item, "target_id"), Jsons.string(item, "title"));
        else if ("private".equals(type)) ChatActivity.openConversation(this, Jsons.longValue(item, "target_id"),
            Jsons.longValue(item, "peer_user_id"), Jsons.string(item, "title"));
        else if ("service".equals(type)) ChatActivity.openUserService(this);
        else startActivity(xyz.jjmxg.yiyunying.ui.main.MainActivity.moduleIntent(this, "bot"));
    }

    private void unhide(JsonObject item) {
        if (actionRequest != null) return;
        JsonObject body = new JsonObject();
        body.addProperty("is_hidden", false);
        String path = "/api/user/message-center/" + Jsons.string(item, "type") + "/"
            + Jsons.longValue(item, "target_id") + "/preference";
        actionRequest = AppAccess.from(this).repository().put(path, body, result -> {
            actionRequest = null;
            if (binding == null) return;
            Snackbar.make(binding.getRoot(), result.isSuccessful() ? "会话已恢复到消息列表"
                : (result.message().isEmpty() ? "取消隐藏失败" : result.message()), Snackbar.LENGTH_LONG).show();
            if (result.isSuccessful()) load();
        });
    }

    private static boolean bool(JsonObject value, String key) {
        try { return value.has(key) && value.get(key).getAsBoolean(); }
        catch (RuntimeException ignored) { return false; }
    }

    @Override protected void onDestroy() {
        if (request != null) request.cancel();
        if (cacheRequest != null) cacheRequest.cancel();
        if (actionRequest != null) actionRequest.cancel();
        binding = null;
        super.onDestroy();
    }
}
