package xyz.jjmxg.yiyunying.ui.home;

import android.content.Context;
import android.content.Intent;
import android.os.Bundle;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;

import androidx.annotation.NonNull;
import androidx.recyclerview.widget.LinearLayoutManager;
import androidx.recyclerview.widget.RecyclerView;

import xyz.jjmxg.yiyunying.ui.common.YiyunyingDialogBuilder;
import com.google.android.material.snackbar.Snackbar;
import com.google.gson.JsonObject;

import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.core.AppAccess;
import xyz.jjmxg.yiyunying.core.RuntimeLanguage;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.data.api.RequestHandle;
import xyz.jjmxg.yiyunying.databinding.ActivityRelationshipNoticeBinding;
import xyz.jjmxg.yiyunying.databinding.ItemRelationshipNoticeBinding;
import xyz.jjmxg.yiyunying.ui.common.ImageLoader;
import xyz.jjmxg.yiyunying.ui.common.RecordDetailDialog;
import xyz.jjmxg.yiyunying.ui.common.SystemInsetActivity;
import xyz.jjmxg.yiyunying.ui.profile.UserProfileActivity;

public final class RelationshipNoticeActivity extends SystemInsetActivity {
    private static final String EXTRA_CATEGORY = "category";
    private static final String EXTRA_TITLE = "title";

    private ActivityRelationshipNoticeBinding binding;
    private final List<JsonObject> items = new ArrayList<>();
    private NoticeAdapter adapter;
    private RequestHandle request;
    private RequestHandle actionRequest;
    private String category;

    public static void open(Context context, String category, String title) {
        context.startActivity(new Intent(context, RelationshipNoticeActivity.class)
            .putExtra(EXTRA_CATEGORY, category)
            .putExtra(EXTRA_TITLE, title));
    }

    @Override protected void onCreate(Bundle state) {
        super.onCreate(state);
        category = getIntent().getStringExtra(EXTRA_CATEGORY);
        if (category == null || category.trim().isEmpty()) category = "friend_incoming";
        binding = ActivityRelationshipNoticeBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());
        binding.toolbar.setTitle(getIntent().getStringExtra(EXTRA_TITLE));
        binding.toolbar.setNavigationOnClickListener(view -> finish());
        binding.policyNotice.setText("正在读取当前应用的申请与邀请规则……");
        adapter = new NoticeAdapter();
        binding.recycler.setLayoutManager(new LinearLayoutManager(this));
        binding.recycler.setAdapter(adapter);
        binding.swipeRefresh.setOnRefreshListener(this::load);
        load();
    }

    private void load() {
        if (request != null) request.cancel();
        binding.progress.setVisibility(View.VISIBLE);
        LinkedHashMap<String, String> query = new LinkedHashMap<>();
        query.put("category", category);
        query.put("limit", "200");
        request = AppAccess.from(this).repository().get("/api/user/relationship-notices", query, result -> {
            request = null;
            if (binding == null) return;
            binding.progress.setVisibility(View.INVISIBLE);
            binding.swipeRefresh.setRefreshing(false);
            if (!result.isSuccessful()) {
                Snackbar.make(binding.getRoot(), result.message().isEmpty() ? "通知加载失败" : result.message(), Snackbar.LENGTH_LONG).show();
                return;
            }
            items.clear();
            items.addAll(result.objectItems());
            renderPolicy(Jsons.object(result.dataObject(), "relationship_policy"));
            adapter.notifyDataSetChanged();
            binding.emptyText.setVisibility(items.isEmpty() ? View.VISIBLE : View.GONE);
        });
    }

    private void renderPolicy(JsonObject policy) {
        int days = Jsons.intValue(policy, "effective_days", 30);
        boolean locked = bool(policy, "locked");
        String source = Jsons.string(policy, "source_name");
        if (source.isEmpty()) source = locked ? "上级规则" : "当前应用规则";
        binding.policyNotice.setText("申请和邀请有效期：" + days + " 天（" + source + "）"
            + (locked ? "，当前规则已由上级强制锁定。" : "。")
            + "忽略后仍可继续同意或拒绝；过期记录会置灰保留，但不能再处理。");
    }

    private void openNotice(JsonObject item) {
        if (bool(item, "can_decide")) {
            new YiyunyingDialogBuilder(this)
                .setBusinessTitle(Jsons.string(item, "title"))
                .setItems(new String[]{"同意", "忽略", "拒绝"}, (dialog, which) -> decide(item, which))
                .setNeutralButton("查看用户资料", (dialog, which) -> openProfile(item))
                .setNegativeButton("取消", null)
                .show();
            return;
        }
        String message = Jsons.string(item, "message");
        if (message.isEmpty()) message = "没有填写附加说明";
        JsonObject detail = new JsonObject();
        detail.addProperty("通知", valueOr(Jsons.string(item, "title"), "申请与邀请通知"));
        detail.addProperty("说明", message);
        detail.addProperty("状态", status(item));
        detail.addProperty("申请时间", valueOr(Jsons.string(item, "created_at"), "未知"));
        detail.addProperty("有效期至", valueOr(Jsons.string(item, "expired_at"), "按应用规则"));
        RecordDetailDialog.show(this, "通知详情", detail, "查看用户资料", () -> openProfile(item));
    }

    private void decide(JsonObject item, int which) {
        if (actionRequest != null) return;
        String type = Jsons.string(item, "notice_type");
        String action = which == 0 ? "accept" : which == 1 ? "ignore" : "reject";
        String path;
        if ("friend_request".equals(type)) {
            path = "/api/user/friends/requests/" + Jsons.longValue(item, "id") + "/" + action;
        } else if ("group_invitation".equals(type)) {
            path = "/api/user/chat-room-invitations/" + Jsons.longValue(item, "id") + "/" + action;
        } else {
            if (which == 0) action = "approve";
            path = "/api/user/chat-rooms/" + Jsons.longValue(item, "room_id")
                + "/join-requests/" + Jsons.longValue(item, "id") + "/" + action;
        }
        JsonObject body = new JsonObject();
        if (which == 1) body.addProperty("reason", "接收方选择忽略，保留后续处理权利");
        binding.progress.setVisibility(View.VISIBLE);
        actionRequest = AppAccess.from(this).repository().post(path, body, result -> {
            actionRequest = null;
            if (binding == null) return;
            binding.progress.setVisibility(View.INVISIBLE);
            String success = which == 0 ? "已同意" : (which == 1 ? "已忽略，可稍后继续处理" : "已拒绝");
            Snackbar.make(binding.getRoot(), result.isSuccessful()
                ? success
                : (result.message().isEmpty() ? "处理失败" : result.message()), Snackbar.LENGTH_LONG).show();
            if (result.isSuccessful()) load();
        });
    }

    private void openProfile(JsonObject item) {
        long userId = Jsons.longValue(item, "subject_user_id");
        if (userId > 0) UserProfileActivity.open(this, userId);
    }

    private String status(JsonObject item) {
        String value = Jsons.string(item, "status_text");
        if (!value.isEmpty()) return value;
        return bool(item, "is_expired") ? "已过期" : "已处理";
    }

    private static String valueOr(String value, String fallback) {
        return value == null || value.trim().isEmpty() ? fallback : value;
    }

    private static boolean bool(JsonObject value, String key) {
        try { return value.has(key) && !value.get(key).isJsonNull() && value.get(key).getAsBoolean(); }
        catch (RuntimeException ignored) { return false; }
    }

    @Override protected void onDestroy() {
        if (request != null) request.cancel();
        if (actionRequest != null) actionRequest.cancel();
        binding = null;
        super.onDestroy();
    }

    private final class NoticeAdapter extends RecyclerView.Adapter<NoticeAdapter.Holder> {
        @NonNull @Override public Holder onCreateViewHolder(@NonNull ViewGroup parent, int viewType) {
            return new Holder(ItemRelationshipNoticeBinding.inflate(LayoutInflater.from(parent.getContext()), parent, false));
        }

        @Override public void onBindViewHolder(@NonNull Holder holder, int position) {
            JsonObject item = items.get(position);
            RuntimeLanguage.setDynamicText(holder.binding.title, Jsons.string(item, "title"));
            RuntimeLanguage.setDynamicText(holder.binding.subtitle, Jsons.string(item, "subtitle"));
            String message = Jsons.string(item, "message");
            RuntimeLanguage.setDynamicText(holder.binding.message, message);
            holder.binding.message.setVisibility(message.isEmpty() ? View.GONE : View.VISIBLE);
            holder.binding.status.setText(status(item));
            boolean expired = bool(item, "is_expired");
            holder.binding.status.setTextColor(expired ? getColor(R.color.error)
                : xyz.jjmxg.yiyunying.ui.common.ThemeColors.primary(RelationshipNoticeActivity.this));
            String expiry = valueOr(Jsons.string(item, "expired_at"), "按应用规则");
            holder.binding.metadata.setText(compact(Jsons.string(item, "created_at"))
                + (expired ? " · 已过期" : " · 有效至 " + compact(expiry)));
            ImageLoader.get().load(ImageLoader.get().absoluteUrl(RelationshipNoticeActivity.this,
                Jsons.string(item, "avatar")), holder.binding.avatar, R.drawable.ic_person);
            holder.binding.getRoot().setAlpha(bool(item, "is_dimmed") ? 0.48f : 1f);
            holder.binding.getRoot().setOnClickListener(view -> openNotice(item));
            holder.binding.viewButton.setOnClickListener(view -> openNotice(item));
        }

        @Override public int getItemCount() { return items.size(); }

        private String compact(String value) {
            if (value == null || value.isEmpty()) return "未知";
            return value.length() >= 16 ? value.substring(0, 16) : value;
        }

        final class Holder extends RecyclerView.ViewHolder {
            final ItemRelationshipNoticeBinding binding;
            Holder(ItemRelationshipNoticeBinding value) { super(value.getRoot()); binding = value; }
        }
    }
}
