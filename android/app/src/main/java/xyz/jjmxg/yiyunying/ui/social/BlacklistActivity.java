package xyz.jjmxg.yiyunying.ui.social;

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
import java.util.Locale;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.core.AppAccess;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.data.api.RequestHandle;
import xyz.jjmxg.yiyunying.databinding.ActivityAddFriendBinding;
import xyz.jjmxg.yiyunying.databinding.ItemAddFriendBinding;
import xyz.jjmxg.yiyunying.ui.common.ImageLoader;
import xyz.jjmxg.yiyunying.ui.common.SystemInsetActivity;
import xyz.jjmxg.yiyunying.ui.profile.UserProfileActivity;

public final class BlacklistActivity extends SystemInsetActivity {
    private ActivityAddFriendBinding binding;
    private final List<JsonObject> items = new ArrayList<>();
    private final List<JsonObject> visible = new ArrayList<>();
    private BlacklistAdapter adapter;
    private RequestHandle request;
    private RequestHandle actionRequest;

    public static void open(Context context) {
        context.startActivity(new Intent(context, BlacklistActivity.class));
    }

    @Override protected void onCreate(Bundle state) {
        super.onCreate(state);
        binding = ActivityAddFriendBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());
        binding.toolbar.setTitle("黑名单管理");
        binding.toolbar.setNavigationOnClickListener(view -> finish());
        binding.searchLayout.setHint("搜索账号、UID 或昵称");
        binding.scanQrButton.setVisibility(View.GONE);
        binding.myQrButton.setVisibility(View.GONE);
        adapter = new BlacklistAdapter();
        binding.recycler.setLayoutManager(new LinearLayoutManager(this));
        binding.recycler.setAdapter(adapter);
        binding.searchLayout.setEndIconOnClickListener(view -> filter());
        binding.searchInput.addTextChangedListener(new android.text.TextWatcher() {
            @Override public void beforeTextChanged(CharSequence s, int start, int count, int after) { }
            @Override public void onTextChanged(CharSequence s, int start, int before, int count) { filter(); }
            @Override public void afterTextChanged(android.text.Editable s) { }
        });
        load();
    }

    private void load() {
        if (request != null) request.cancel();
        binding.progress.setVisibility(View.VISIBLE);
        request = AppAccess.from(this).repository().get("/api/user/blacklist", new LinkedHashMap<>(), result -> {
            request = null;
            if (binding == null) return;
            binding.progress.setVisibility(View.INVISIBLE);
            if (!result.isSuccessful()) {
                Snackbar.make(binding.getRoot(), result.message().isEmpty() ? "黑名单加载失败" : result.message(), Snackbar.LENGTH_LONG).show();
                return;
            }
            items.clear();
            items.addAll(result.objectItems());
            filter();
        });
    }

    private void filter() {
        if (binding == null) return;
        String query = binding.searchInput.getText() == null ? "" : binding.searchInput.getText().toString().trim().toLowerCase(Locale.ROOT);
        visible.clear();
        for (JsonObject item : items) {
            String text = (Jsons.string(item, "nickname") + " " + Jsons.string(item, "account")
                + " " + Jsons.string(item, "uid")).toLowerCase(Locale.ROOT);
            if (query.isEmpty() || text.contains(query)) visible.add(item);
        }
        adapter.notifyDataSetChanged();
        binding.emptyText.setText(query.isEmpty() ? "黑名单为空" : "没有找到符合条件的用户");
        binding.emptyText.setVisibility(visible.isEmpty() ? View.VISIBLE : View.GONE);
    }

    private void confirmRemove(JsonObject item) {
        new YiyunyingDialogBuilder(this)
            .setTitle("移出黑名单")
            .setMessage("移出后，对方将按你的消息与隐私设置重新获得互动权限。")
            .setPositiveButton("确认移出", (dialog, which) -> remove(item))
            .setNegativeButton("取消", null)
            .show();
    }

    private void remove(JsonObject item) {
        if (actionRequest != null) return;
        long userId = Jsons.longValue(item, "user_id");
        if (userId <= 0) userId = Jsons.longValue(item, "blocked_user_id");
        actionRequest = AppAccess.from(this).repository().post("/api/user/blacklist/" + userId, new JsonObject(), result -> {
            actionRequest = null;
            if (binding == null) return;
            Snackbar.make(binding.getRoot(), result.isSuccessful() ? "已移出黑名单"
                : (result.message().isEmpty() ? "操作失败" : result.message()), Snackbar.LENGTH_LONG).show();
            if (result.isSuccessful()) load();
        });
    }

    @Override protected void onDestroy() {
        if (request != null) request.cancel();
        if (actionRequest != null) actionRequest.cancel();
        binding = null;
        super.onDestroy();
    }

    private final class BlacklistAdapter extends RecyclerView.Adapter<BlacklistAdapter.Holder> {
        @NonNull @Override public Holder onCreateViewHolder(@NonNull ViewGroup parent, int viewType) {
            return new Holder(ItemAddFriendBinding.inflate(LayoutInflater.from(parent.getContext()), parent, false));
        }

        @Override public void onBindViewHolder(@NonNull Holder holder, int position) {
            JsonObject item = visible.get(position);
            String nickname = Jsons.string(item, "nickname");
            holder.binding.title.setText(nickname.isEmpty() ? Jsons.string(item, "account") : nickname);
            holder.binding.subtitle.setText("账号：" + Jsons.string(item, "account") + " · UID：" + Jsons.string(item, "uid"));
            holder.binding.relation.setText("已加入黑名单 · 点击可查看基础资料");
            holder.binding.addButton.setText("移出");
            holder.binding.addButton.setEnabled(true);
            ImageLoader.get().load(ImageLoader.get().absoluteUrl(BlacklistActivity.this,
                Jsons.string(item, "avatar")), holder.binding.avatar, R.drawable.ic_person);
            long userId = Jsons.longValue(item, "user_id");
            if (userId <= 0) userId = Jsons.longValue(item, "blocked_user_id");
            long target = userId;
            holder.binding.getRoot().setOnClickListener(view -> UserProfileActivity.open(BlacklistActivity.this, target));
            holder.binding.addButton.setOnClickListener(view -> confirmRemove(item));
        }

        @Override public int getItemCount() { return visible.size(); }

        final class Holder extends RecyclerView.ViewHolder {
            final ItemAddFriendBinding binding;
            Holder(ItemAddFriendBinding value) { super(value.getRoot()); binding = value; }
        }
    }
}
