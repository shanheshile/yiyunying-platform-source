package xyz.jjmxg.yiyunying.ui.management;

import android.content.Context;
import android.content.Intent;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.text.Editable;
import android.text.TextWatcher;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;

import androidx.annotation.NonNull;
import androidx.appcompat.app.AppCompatActivity;
import androidx.recyclerview.widget.LinearLayoutManager;
import androidx.recyclerview.widget.RecyclerView;

import com.google.android.material.snackbar.Snackbar;
import com.google.gson.JsonArray;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;

import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

import xyz.jjmxg.yiyunying.core.AppAccess;
import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.core.RuntimeLanguage;
import xyz.jjmxg.yiyunying.data.api.RequestHandle;
import xyz.jjmxg.yiyunying.databinding.ActivityForumListBinding;
import xyz.jjmxg.yiyunying.databinding.ItemRecordBinding;
import xyz.jjmxg.yiyunying.domain.Role;
import xyz.jjmxg.yiyunying.ui.auth.LoginActivity;
import xyz.jjmxg.yiyunying.ui.common.RecordDetailDialog;
import xyz.jjmxg.yiyunying.ui.forum.ForumListActivity;

public final class ManagedUsersActivity extends xyz.jjmxg.yiyunying.ui.common.SystemInsetActivity {
    private static final String EXTRA_APP_ID = "app_id";
    private static final String EXTRA_APP_NAME = "app_name";

    private final Handler handler = new Handler(Looper.getMainLooper());
    private final Runnable delayedSearch = this::load;
    private ActivityForumListBinding binding;
    private UserAdapter adapter;
    private RequestHandle request;
    private long appId;

    public static void open(Context context, long appId, String appName) {
        context.startActivity(new Intent(context, ManagedUsersActivity.class)
            .putExtra(EXTRA_APP_ID, appId).putExtra(EXTRA_APP_NAME, appName));
    }

    @Override protected void onCreate(Bundle state) {
        super.onCreate(state);
        if (!AppAccess.from(this).session().isAuthenticated()) { login(); return; }
        appId = getIntent().getLongExtra(EXTRA_APP_ID, 0);
        if (appId <= 0) { finish(); return; }
        binding = ActivityForumListBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());
        String name = getIntent().getStringExtra(EXTRA_APP_NAME);
        binding.toolbar.setTitle((name == null || name.isEmpty() ? "应用" : name) + " · 用户监管");
        binding.toolbar.setNavigationOnClickListener(view -> finish());
        android.view.MenuItem forum = binding.toolbar.getMenu().add("论坛");
        forum.setIcon(R.drawable.ic_forum);
        forum.setShowAsAction(android.view.MenuItem.SHOW_AS_ACTION_ALWAYS);
        forum.setOnMenuItemClickListener(item -> {
            ForumListActivity.openForApp(this, appId, name == null ? "应用" : name);
            return true;
        });
        binding.searchLayout.setVisibility(View.VISIBLE);
        binding.searchLayout.setHint("搜索账号、昵称、邮箱或手机");
        binding.createButton.setVisibility(View.GONE);
        adapter = new UserAdapter(new UserAdapter.Listener() {
            @Override public void onClick(JsonObject user) {
                ManagedUserDetailActivity.open(ManagedUsersActivity.this, appId,
                    Jsons.longValue(user, "id"), Jsons.string(user, "nickname"));
            }

            @Override public void onLongPress(JsonObject user) {
                RecordDetailDialog.show(ManagedUsersActivity.this, "用户摘要", user);
            }
        });
        binding.recycler.setLayoutManager(new LinearLayoutManager(this));
        binding.recycler.setAdapter(adapter);
        binding.swipeRefresh.setOnRefreshListener(this::load);
        binding.searchInput.addTextChangedListener(new TextWatcher() {
            @Override public void beforeTextChanged(CharSequence value, int start, int count, int after) { }
            @Override public void onTextChanged(CharSequence value, int start, int before, int count) {
                handler.removeCallbacks(delayedSearch);
                handler.postDelayed(delayedSearch, 350L);
            }
            @Override public void afterTextChanged(Editable value) { }
        });
        load();
    }

    private void load() {
        handler.removeCallbacks(delayedSearch);
        if (request != null) request.cancel();
        binding.progress.setVisibility(View.VISIBLE);
        Map<String, String> query = new LinkedHashMap<>();
        query.put("limit", "100");
        String keyword = binding.searchInput.getText() == null ? "" : binding.searchInput.getText().toString().trim();
        if (!keyword.isEmpty()) query.put("keyword", keyword);
        String prefix = AppAccess.from(this).session().role() == Role.PLATFORM ? "/api/platform" : "/api/admin";
        request = AppAccess.from(this).repository().get(prefix + "/apps/" + appId + "/users", query, result -> {
            request = null;
            if (binding == null) return;
            binding.progress.setVisibility(View.INVISIBLE);
            binding.swipeRefresh.setRefreshing(false);
            if (result.isAuthenticationFailure()) { login(); return; }
            if (!result.isSuccessful()) {
                Snackbar.make(binding.getRoot(), result.message().isEmpty() ? "用户列表加载失败" : result.message(), Snackbar.LENGTH_LONG).show();
                return;
            }
            List<JsonObject> users = objects(result.items());
            adapter.submit(users);
            binding.emptyText.setText("这个应用还没有用户");
            binding.emptyText.setVisibility(users.isEmpty() ? View.VISIBLE : View.GONE);
        });
    }

    private void login() {
        AppAccess.from(this).session().clearAuthentication();
        startActivity(new Intent(this, LoginActivity.class).putExtra(LoginActivity.EXTRA_FORCE_LOGIN, true)
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TASK));
        finish();
    }

    private static List<JsonObject> objects(JsonArray array) {
        List<JsonObject> result = new ArrayList<>();
        for (JsonElement element : array) if (element.isJsonObject()) result.add(element.getAsJsonObject());
        return result;
    }

    @Override protected void onDestroy() {
        handler.removeCallbacks(delayedSearch);
        if (request != null) request.cancel();
        binding = null;
        super.onDestroy();
    }

    private static final class UserAdapter extends RecyclerView.Adapter<UserAdapter.Holder> {
        interface Listener {
            void onClick(JsonObject user);
            void onLongPress(JsonObject user);
        }
        private final Listener listener;
        private final List<JsonObject> items = new ArrayList<>();
        UserAdapter(Listener listener) { this.listener = listener; setHasStableIds(true); }
        void submit(List<JsonObject> next) { items.clear(); items.addAll(next); notifyDataSetChanged(); }
        @Override public long getItemId(int position) { return Jsons.longValue(items.get(position), "id"); }
        @NonNull @Override public Holder onCreateViewHolder(@NonNull ViewGroup parent, int viewType) {
            return new Holder(ItemRecordBinding.inflate(LayoutInflater.from(parent.getContext()), parent, false));
        }
        @Override public void onBindViewHolder(@NonNull Holder holder, int position) {
            JsonObject item = items.get(position);
            String nickname = Jsons.string(item, "nickname");
            String account = Jsons.string(item, "account");
            RuntimeLanguage.setDynamicText(holder.binding.title, nickname.isEmpty() ? account : nickname);
            RuntimeLanguage.setDynamicText(holder.binding.subtitle,
                RuntimeLanguage.translate(holder.itemView.getContext(), "账号") + " " + account + " · "
                    + RuntimeLanguage.translate(holder.itemView.getContext(), "余额") + " " + Jsons.string(item, "balance"));
            RuntimeLanguage.setDynamicText(holder.binding.metadata,
                RuntimeLanguage.translate(holder.itemView.getContext(), "等级") + " " + Jsons.string(item, "level_code") + " · "
                    + RuntimeLanguage.translate(holder.itemView.getContext(), "文档") + " "
                    + Jsons.longValue(item, "document_credit") + " · "
                    + RuntimeLanguage.translate(holder.itemView.getContext(), "状态") + " " + Jsons.longValue(item, "status"));
            String avatar = nickname.isEmpty() ? account : nickname;
            RuntimeLanguage.setDynamicText(holder.binding.avatar, avatar.isEmpty() ? "用" : avatar.substring(0, 1));
            holder.binding.moreButton.setVisibility(View.GONE);
            holder.binding.selectionCheck.setVisibility(View.GONE);
            holder.binding.getRoot().setOnClickListener(view -> listener.onClick(item));
            holder.binding.getRoot().setOnLongClickListener(view -> {
                listener.onLongPress(item);
                return true;
            });
        }
        @Override public int getItemCount() { return items.size(); }
        static final class Holder extends RecyclerView.ViewHolder {
            final ItemRecordBinding binding;
            Holder(ItemRecordBinding binding) { super(binding.getRoot()); this.binding = binding; }
        }
    }
}
