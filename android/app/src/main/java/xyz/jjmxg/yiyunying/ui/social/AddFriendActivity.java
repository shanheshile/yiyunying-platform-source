package xyz.jjmxg.yiyunying.ui.social;

import android.content.Context;
import android.content.Intent;
import android.os.Bundle;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.view.inputmethod.EditorInfo;

import androidx.annotation.NonNull;
import androidx.recyclerview.widget.LinearLayoutManager;
import androidx.recyclerview.widget.RecyclerView;

import com.google.android.material.snackbar.Snackbar;
import com.google.gson.JsonObject;

import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.core.AppAccess;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.data.api.RequestHandle;
import xyz.jjmxg.yiyunying.databinding.ActivityAddFriendBinding;
import xyz.jjmxg.yiyunying.databinding.ItemAddFriendBinding;
import xyz.jjmxg.yiyunying.ui.common.ImageLoader;
import xyz.jjmxg.yiyunying.ui.common.SystemInsetActivity;
import xyz.jjmxg.yiyunying.ui.profile.UserProfileActivity;

public final class AddFriendActivity extends SystemInsetActivity {
    private ActivityAddFriendBinding binding;
    private final List<JsonObject> items = new ArrayList<>();
    private SearchAdapter adapter;
    private RequestHandle request;

    public static void open(Context context) {
        context.startActivity(new Intent(context, AddFriendActivity.class));
    }

    @Override protected void onCreate(Bundle state) {
        super.onCreate(state);
        binding = ActivityAddFriendBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());
        binding.toolbar.setNavigationOnClickListener(view -> finish());
        adapter = new SearchAdapter();
        binding.recycler.setLayoutManager(new LinearLayoutManager(this));
        binding.recycler.setAdapter(adapter);
        binding.searchLayout.setEndIconOnClickListener(view -> search());
        binding.searchInput.setOnEditorActionListener((view, action, event) -> {
            if (action == EditorInfo.IME_ACTION_SEARCH) { search(); return true; }
            return false;
        });
        binding.scanQrButton.setOnClickListener(view -> FriendQrActivity.open(this, true));
        binding.myQrButton.setOnClickListener(view -> FriendQrActivity.open(this, false));
    }

    private void search() {
        String keyword = binding.searchInput.getText() == null ? "" : binding.searchInput.getText().toString().trim();
        if (keyword.isEmpty()) {
            binding.searchLayout.setError("请输入 UID、账号或昵称");
            return;
        }
        binding.searchLayout.setError(null);
        if (request != null) request.cancel();
        binding.progress.setVisibility(View.VISIBLE);
        LinkedHashMap<String, String> query = new LinkedHashMap<>();
        query.put("keyword", keyword);
        query.put("limit", "100");
        request = AppAccess.from(this).repository().get("/api/user/users/search", query, result -> {
            request = null;
            if (binding == null) return;
            binding.progress.setVisibility(View.INVISIBLE);
            if (!result.isSuccessful()) {
                Snackbar.make(binding.getRoot(), result.message().isEmpty() ? "用户搜索失败" : result.message(), Snackbar.LENGTH_LONG).show();
                return;
            }
            items.clear();
            items.addAll(result.objectItems());
            adapter.notifyDataSetChanged();
            binding.emptyText.setText(items.isEmpty() ? "没有找到符合条件的用户" : "");
            binding.emptyText.setVisibility(items.isEmpty() ? View.VISIBLE : View.GONE);
        });
    }

    private void openProfile(JsonObject item) {
        UserProfileActivity.open(this, Jsons.longValue(item, "user_id"));
    }

    private String actionText(JsonObject item) {
        if (bool(item, "is_self")) return "自己";
        if (bool(item, "is_friend")) return "已是好友";
        if ("pending".equals(Jsons.string(item, "outgoing_request_status"))) return "已申请";
        if (!bool(item, "allow_friend_requests")) return "不可添加";
        return bool(item, "can_send_friend_request") ? "加好友" : "查看";
    }

    private static boolean bool(JsonObject item, String key) {
        try { return item.has(key) && item.get(key).getAsBoolean(); }
        catch (RuntimeException ignored) { return false; }
    }

    @Override protected void onDestroy() {
        if (request != null) request.cancel();
        binding = null;
        super.onDestroy();
    }

    private final class SearchAdapter extends RecyclerView.Adapter<SearchAdapter.Holder> {
        @NonNull @Override public Holder onCreateViewHolder(@NonNull ViewGroup parent, int viewType) {
            return new Holder(ItemAddFriendBinding.inflate(LayoutInflater.from(parent.getContext()), parent, false));
        }

        @Override public void onBindViewHolder(@NonNull Holder holder, int position) {
            JsonObject item = items.get(position);
            String nickname = Jsons.string(item, "nickname");
            holder.binding.title.setText(nickname.isEmpty() ? Jsons.string(item, "account") : nickname);
            holder.binding.subtitle.setText("账号：" + Jsons.string(item, "account") + " · UID：" + Jsons.string(item, "uid"));
            holder.binding.relation.setText(Jsons.string(item, "relation_name") + " · "
                + Jsons.string(item, "profile_visibility_name"));
            ImageLoader.get().load(ImageLoader.get().absoluteUrl(AddFriendActivity.this,
                Jsons.string(item, "avatar")), holder.binding.avatar, R.drawable.ic_person);
            String action = actionText(item);
            holder.binding.addButton.setText(action);
            holder.binding.addButton.setEnabled(bool(item, "can_send_friend_request") || "查看".equals(action));
            holder.binding.getRoot().setOnClickListener(view -> openProfile(item));
            holder.binding.avatar.setOnClickListener(view -> openProfile(item));
            holder.binding.addButton.setOnClickListener(view -> {
                if (bool(item, "can_send_friend_request")) {
                    FriendRequestActivity.open(AddFriendActivity.this, Jsons.longValue(item, "user_id"));
                } else openProfile(item);
            });
        }

        @Override public int getItemCount() { return items.size(); }

        final class Holder extends RecyclerView.ViewHolder {
            final ItemAddFriendBinding binding;
            Holder(ItemAddFriendBinding value) { super(value.getRoot()); binding = value; }
        }
    }
}
