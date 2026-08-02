package xyz.jjmxg.yiyunying.ui.social;

import android.app.Activity;
import android.content.Context;
import android.content.Intent;
import android.os.Bundle;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.widget.CheckBox;
import android.widget.EditText;
import android.widget.ImageView;
import android.widget.ProgressBar;
import android.widget.TextView;

import androidx.annotation.NonNull;
import androidx.recyclerview.widget.LinearLayoutManager;
import androidx.recyclerview.widget.RecyclerView;

import com.google.android.material.button.MaterialButton;
import com.google.android.material.snackbar.Snackbar;
import com.google.gson.JsonArray;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;

import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Locale;
import java.util.Map;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.core.AppAccess;
import xyz.jjmxg.yiyunying.core.CrashReporter;
import xyz.jjmxg.yiyunying.ui.common.ImageLoader;
import xyz.jjmxg.yiyunying.core.RuntimeLanguage;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.data.api.RequestHandle;
import xyz.jjmxg.yiyunying.databinding.ActivityFriendPickerBinding;
import xyz.jjmxg.yiyunying.ui.common.SystemInsetActivity;

/**
 * 多选好友选择器。用于「默认可见对象 / 仅指定用户 / 除指定用户外」等场景，
 * 返回选中的 user_id 列表（{@link #EXTRA_SELECTED_IDS}）。
 */
public final class FriendPickerActivity extends SystemInsetActivity {
    public static final String EXTRA_SELECTED_IDS = "selected_user_ids";
    public static final String EXTRA_TITLE = "picker_title";

    private ActivityFriendPickerBinding binding;
    private RequestHandle request;
    private final Map<Long, JsonObject> friends = new LinkedHashMap<>();
    private final List<JsonObject> shown = new ArrayList<>();
    private final List<Long> selected = new ArrayList<>();

    public static Intent pickerIntent(Context context, List<Long> preselected, String title) {
        Intent intent = new Intent(context, FriendPickerActivity.class);
        if (preselected != null && !preselected.isEmpty()) {
            intent.putExtra(EXTRA_SELECTED_IDS, toLongArray(preselected));
        }
        if (title != null && !title.isEmpty()) intent.putExtra(EXTRA_TITLE, title);
        return intent;
    }

    @Override protected void onCreate(Bundle state) {
        super.onCreate(state);
        binding = ActivityFriendPickerBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());
        long[] preset = getIntent().getLongArrayExtra(EXTRA_SELECTED_IDS);
        if (preset != null) for (long id : preset) selected.add(id);
        String title = getIntent().getStringExtra(EXTRA_TITLE);
        binding.toolbar.setTitle(RuntimeLanguage.translate(this, title == null || title.isEmpty() ? "选择好友" : title));
        binding.toolbar.setNavigationOnClickListener(view -> finish());
        binding.list.setLayoutManager(new LinearLayoutManager(this));
        binding.list.setAdapter(new Adapter());
        binding.searchInput.addTextChangedListener(new SimpleWatcher() {
            @Override public void afterTextChanged(android.text.Editable value) {
                filter(value == null ? "" : value.toString());
            }
        });
        binding.confirmButton.setOnClickListener(view -> {
            Intent data = new Intent();
            data.putExtra(EXTRA_SELECTED_IDS, toLongArray(selected));
            setResult(Activity.RESULT_OK, data);
            finish();
        });
        updateCount();
        load();
    }

    private void load() {
        if (request != null) request.cancel();
        binding.progress.setVisibility(View.VISIBLE);
        Map<String, String> query = new LinkedHashMap<>();
        query.put("limit", "200");
        request = AppAccess.from(this).repository().get("/api/user/friends", query, result -> {
            request = null;
            if (binding == null) return;
            binding.progress.setVisibility(View.GONE);
            if (!result.isSuccessful()) {
                Snackbar.make(binding.getRoot(),
                    result.message().isEmpty() ? "好友列表加载失败" : result.message(), Snackbar.LENGTH_LONG).show();
                return;
            }
            friends.clear();
            JsonArray items = Jsons.array(result.dataObject(), "items");
            if (items.size() == 0) items = Jsons.array(result.dataObject(), "list");
            for (JsonElement element : items) {
                if (!element.isJsonObject()) continue;
                JsonObject item = element.getAsJsonObject();
                long id = Jsons.longValue(item, "user_id");
                if (id <= 0) id = Jsons.longValue(item, "id");
                if (id <= 0) continue;
                friends.put(id, item);
            }
            filter(binding.searchInput.getText() == null ? "" : binding.searchInput.getText().toString());
            if (friends.isEmpty()) {
                Snackbar.make(binding.getRoot(), "你还没有好友，无法选择", Snackbar.LENGTH_SHORT).show();
            }
        });
    }

    private void filter(String keyword) {
        String key = keyword == null ? "" : keyword.trim().toLowerCase(Locale.ROOT);
        shown.clear();
        for (JsonObject item : friends.values()) {
            if (key.isEmpty()) { shown.add(item); continue; }
            String name = firstText(item, "remark", "nickname", "display_name")
                .toLowerCase(Locale.ROOT);
            String account = Jsons.string(item, "account").toLowerCase(Locale.ROOT);
            String uid = Jsons.string(item, "uid").toLowerCase(Locale.ROOT);
            if (name.contains(key) || account.contains(key) || uid.contains(key)) shown.add(item);
        }
        if (binding.list.getAdapter() != null) binding.list.getAdapter().notifyDataSetChanged();
        binding.emptyHint.setVisibility(shown.isEmpty() ? View.VISIBLE : View.GONE);
    }

    private final class Adapter extends RecyclerView.Adapter<Adapter.Holder> {
        @NonNull @Override public Holder onCreateViewHolder(@NonNull ViewGroup parent, int viewType) {
            return new Holder(LayoutInflater.from(parent.getContext())
                .inflate(R.layout.item_friend_pick, parent, false));
        }

        @Override public void onBindViewHolder(@NonNull Holder holder, int position) {
            JsonObject item = shown.get(position);
            long id = Jsons.longValue(item, "user_id");
            if (id <= 0) id = Jsons.longValue(item, "id");
            final long selectedId = id;
            String name = firstText(item, "remark", "nickname", "account", "uid");
            holder.name.setText(name.isEmpty() ? "未命名好友" : name);
            String account = Jsons.string(item, "account");
            String group = Jsons.string(item, "group_name");
            String title = Jsons.string(item, "title");
            StringBuilder detail = new StringBuilder();
            if (!group.isEmpty()) detail.append(group);
            if (!account.isEmpty() && !account.equals(name)) {
                if (detail.length() > 0) detail.append(" · ");
                detail.append(account);
            }
            if (!title.isEmpty()) {
                if (detail.length() > 0) detail.append(" · ");
                detail.append(title);
            }
            holder.title.setVisibility(detail.length() == 0 ? View.GONE : View.VISIBLE);
            holder.title.setText(detail.toString());
            ImageLoader.get().loadThumbnail(
                ImageLoader.get().absoluteUrl(FriendPickerActivity.this, Jsons.string(item, "avatar")),
                holder.avatar, R.drawable.ic_person);
            holder.check.setChecked(selected.contains(selectedId));
            holder.itemView.setOnClickListener(view -> {
                if (selected.contains(selectedId)) selected.remove(selectedId);
                else selected.add(selectedId);
                holder.check.setChecked(selected.contains(selectedId));
                updateCount();
            });
        }

        @Override public int getItemCount() { return shown.size(); }

        final class Holder extends RecyclerView.ViewHolder {
            final ImageView avatar;
            final TextView name;
            final TextView title;
            final CheckBox check;
            Holder(android.view.View item) {
                super(item);
                avatar = item.findViewById(R.id.avatar);
                name = item.findViewById(R.id.nameText);
                title = item.findViewById(R.id.titleText);
                check = item.findViewById(R.id.check);
            }
        }
    }

    private void updateCount() {
        binding.confirmButton.setText(RuntimeLanguage.translate(this, "确定") + "（" + selected.size() + "）");
    }

    private static String firstText(JsonObject item, String... keys) {
        if (item == null || keys == null) return "";
        for (String key : keys) {
            String value = Jsons.string(item, key).trim();
            if (!value.isEmpty()) return value;
        }
        return "";
    }

    private static long[] toLongArray(List<Long> values) {
        if (values == null || values.isEmpty()) return new long[0];
        long[] result = new long[values.size()];
        for (int index = 0; index < values.size(); index++) {
            Long value = values.get(index);
            result[index] = value == null ? 0L : value;
        }
        return result;
    }
    @Override protected void onDestroy() {
        if (request != null) request.cancel();
        binding = null;
        super.onDestroy();
    }

    private abstract static class SimpleWatcher implements android.text.TextWatcher {
        @Override public void beforeTextChanged(CharSequence s, int start, int count, int after) { }
        @Override public void onTextChanged(CharSequence s, int start, int before, int count) { }
    }
}
