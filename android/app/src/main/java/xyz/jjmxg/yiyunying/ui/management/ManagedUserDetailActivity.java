package xyz.jjmxg.yiyunying.ui.management;

import android.content.Context;
import android.content.Intent;
import android.os.Bundle;
import android.view.Gravity;
import android.view.View;
import android.view.ViewGroup;
import android.widget.LinearLayout;
import android.widget.TextView;

import androidx.appcompat.app.AppCompatActivity;

import com.google.android.material.card.MaterialCardView;
import com.google.android.material.snackbar.Snackbar;
import com.google.gson.JsonArray;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;

import java.util.LinkedHashMap;
import java.util.Map;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.core.AppAccess;
import xyz.jjmxg.yiyunying.core.RuntimeLanguage;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.data.api.RequestHandle;
import xyz.jjmxg.yiyunying.databinding.ActivityManagedUserDetailBinding;
import xyz.jjmxg.yiyunying.domain.Role;
import xyz.jjmxg.yiyunying.ui.common.DisplayText;
import xyz.jjmxg.yiyunying.ui.common.ImageLoader;
import xyz.jjmxg.yiyunying.ui.common.RecordDetailDialog;
import xyz.jjmxg.yiyunying.ui.forum.ForumPostActivity;
import xyz.jjmxg.yiyunying.ui.permission.RolePermissionActivity;

public final class ManagedUserDetailActivity extends xyz.jjmxg.yiyunying.ui.common.SystemInsetActivity {
    private static final String EXTRA_APP_ID = "app_id";
    private static final String EXTRA_USER_ID = "user_id";
    private static final String EXTRA_NAME = "name";

    private ActivityManagedUserDetailBinding binding;
    private RequestHandle request;
    private long appId;
    private long userId;
    private String currentName = "";
    private String currentAccount = "";

    public static void open(Context context, long appId, long userId, String name) {
        context.startActivity(new Intent(context, ManagedUserDetailActivity.class)
            .putExtra(EXTRA_APP_ID, appId).putExtra(EXTRA_USER_ID, userId).putExtra(EXTRA_NAME, name));
    }

    @Override protected void onCreate(Bundle state) {
        super.onCreate(state);
        appId = getIntent().getLongExtra(EXTRA_APP_ID, 0);
        userId = getIntent().getLongExtra(EXTRA_USER_ID, 0);
        if (appId <= 0 || userId <= 0) { finish(); return; }
        binding = ActivityManagedUserDetailBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());
        binding.toolbar.setNavigationOnClickListener(view -> finish());
        String title = getIntent().getStringExtra(EXTRA_NAME);
        if (title == null || title.isEmpty()) binding.toolbar.setTitle("用户监管资料");
        else RuntimeLanguage.setDynamicToolbarTitle(binding.toolbar, title);
        currentName = title == null ? "" : title;
        binding.permissionButton.setOnClickListener(view ->
            RolePermissionActivity.openUser(this, appId, userId, currentName, currentAccount));
        load();
    }

    private void load() {
        if (request != null) request.cancel();
        binding.progress.setVisibility(View.VISIBLE);
        Role role = AppAccess.from(this).session().role();
        String path = role == Role.PLATFORM
            ? "/api/platform/apps/" + appId + "/users/" + userId + "/overview"
            : "/api/admin/apps/" + appId + "/users/" + userId;
        request = AppAccess.from(this).repository().get(path, new LinkedHashMap<>(), result -> {
            request = null;
            if (binding == null) return;
            binding.progress.setVisibility(View.INVISIBLE);
            if (!result.isSuccessful()) {
                Snackbar.make(binding.getRoot(), result.message().isEmpty() ? "用户监管资料加载失败" : result.message(), Snackbar.LENGTH_LONG).show();
                return;
            }
            render(result.dataObject());
        });
    }

    private void render(JsonObject data) {
        JsonObject user = Jsons.object(data, "user");
        JsonObject sections = Jsons.object(data, "sections");
        String nickname = Jsons.string(user, "nickname");
        String account = Jsons.string(user, "account");
        currentName = nickname.isEmpty() ? account : nickname;
        currentAccount = account;
        RuntimeLanguage.setDynamicText(binding.name, currentName);
        RuntimeLanguage.setDynamicText(binding.account,
            RuntimeLanguage.translate(this, "账号") + " " + account + " · "
                + RuntimeLanguage.translate(this, "用户编号") + " " + userId);
        ImageLoader.get().load(Jsons.string(user, "avatar"), binding.avatar, R.drawable.ic_person);
        binding.sectionsContainer.removeAllViews();
        for (Map.Entry<String, JsonElement> section : sections.entrySet()) {
            if (!section.getValue().isJsonObject()) continue;
            addSectionTitle(section.getKey());
            for (Map.Entry<String, JsonElement> group : section.getValue().getAsJsonObject().entrySet()) {
                addGroup(group.getKey(), group.getValue());
            }
        }
    }

    private void addSectionTitle(String title) {
        TextView heading = new TextView(this);
        heading.setText(title);
        heading.setTextAppearance(com.google.android.material.R.style.TextAppearance_Material3_TitleLarge);
        LinearLayout.LayoutParams params = params(20);
        binding.sectionsContainer.addView(heading, params);
    }

    private void addGroup(String groupName, JsonElement value) {
        TextView label = new TextView(this);
        int count = value.isJsonArray() ? value.getAsJsonArray().size() : (value.isJsonNull() ? 0 : 1);
        label.setText(groupName + (value.isJsonArray() ? "（" + count + "）" : ""));
        label.setTextAppearance(com.google.android.material.R.style.TextAppearance_Material3_TitleMedium);
        binding.sectionsContainer.addView(label, params(12));
        if (value.isJsonArray()) {
            JsonArray items = value.getAsJsonArray();
            if (items.isEmpty()) addEmpty();
            for (JsonElement item : items) if (item.isJsonObject()) addRecord(groupName, item.getAsJsonObject());
        } else if (value.isJsonObject()) {
            addRecord(groupName, value.getAsJsonObject());
        } else {
            addValue(groupName, DisplayText.value(value));
        }
    }

    private void addRecord(String groupName, JsonObject item) {
        MaterialCardView card = new MaterialCardView(this);
        card.setRadius(dp(7));
        card.setCardElevation(0);
        card.setStrokeWidth(dp(1));
        card.setStrokeColor(getColor(R.color.outline));
        card.setCardBackgroundColor(getColor(R.color.surface_container));
        TextView text = new TextView(this);
        text.setGravity(Gravity.CENTER_VERTICAL);
        text.setMinHeight(dp(64));
        text.setPadding(dp(14), dp(10), dp(14), dp(10));
        RuntimeLanguage.setDynamicText(text, recordText(groupName, item));
        text.setTextAppearance(com.google.android.material.R.style.TextAppearance_Material3_BodyLarge);
        card.addView(text);
        card.setOnClickListener(view -> openRecord(groupName, item));
        card.setOnLongClickListener(view -> {
            RecordDetailDialog.show(this, groupName + "信息", item);
            return true;
        });
        binding.sectionsContainer.addView(card, params(7));
    }

    private String recordText(String groupName, JsonObject item) {
        if (isRelatedUserGroup(groupName)) {
            String name = Jsons.string(item, "nickname");
            if (name.isEmpty()) name = Jsons.string(item, "account");
            return name + "\n账号 " + Jsons.string(item, "account") + " · 点按继续查看该用户的完整关系与内容";
        }
        if ("私聊会话".equals(groupName)) {
            String name = Jsons.string(item, "peer_name");
            if (name.isEmpty()) name = Jsons.string(item, "peer_account");
            return binding.name.getText() + " 与 " + name + "\n"
                + fallback(Jsons.string(item, "last_message"), "暂无最近消息") + " · 点按进入这段私聊";
        }
        if ("群聊".equals(groupName)) {
            return fallback(Jsons.string(item, "name"), "群聊 #" + Jsons.longValue(item, "id"))
                + "\n消息 " + Jsons.longValue(item, "message_count") + " 条 · 点按以 " + binding.name.getText() + " 的视角进入群聊";
        }
        if ("聊天室".equals(groupName)) {
            return fallback(Jsons.string(item, "name"), "聊天室 #" + Jsons.longValue(item, "id"))
                + "\n消息 " + Jsons.longValue(item, "message_count") + " 条 · 点按以管理员视角进入聊天室";
        }
        if ("客服会话".equals(groupName)) {
            return fallback(Jsons.string(item, "subject"), "客服会话 #" + Jsons.longValue(item, "id"))
                + "\n状态 " + DisplayText.status(item.get("status")) + " · 点按查看客服消息";
        }
        String title = first(item, "title", "name", "account", "content", "scene", "module", "order_no");
        if (title.isEmpty()) title = groupName + " #" + Jsons.longValue(item, "id");
        return title + "\n点按查看完整中文详情，长按快速查看";
    }

    private void openRecord(String groupName, JsonObject item) {
        if (isRelatedUserGroup(groupName)) {
            long friendId = Jsons.longValue(item, "user_id");
            if (friendId > 0) open(this, appId, friendId, first(item, "nickname", "account"));
            return;
        }
        if ("论坛帖子".equals(groupName)) {
            long postId = Jsons.longValue(item, "id");
            if (postId > 0) ForumPostActivity.open(this, appId, postId);
            return;
        }
        String type = null;
        if ("私聊会话".equals(groupName)) type = "private";
        else if ("群聊".equals(groupName)) type = "group";
        else if ("聊天室".equals(groupName)) type = "chat_room";
        else if ("客服会话".equals(groupName)) type = "service";
        if (type != null) {
            ManagedCommunicationActivity.open(this, appId, userId, type, Jsons.longValue(item, "id"),
                groupName + " · " + first(item, "peer_name", "peer_account", "name", "subject") + "（管理员视角）");
            return;
        }
        ManagedRecordDetailActivity.open(this, groupName, item);
    }

    private void addEmpty() { addValue("状态", "暂无记录"); }

    private void addValue(String label, String value) {
        TextView text = new TextView(this);
        RuntimeLanguage.setDynamicText(text,
            RuntimeLanguage.translate(this, label) + "：" + fallback(value, "-"));
        text.setTextColor(getColor(R.color.on_surface_variant));
        text.setPadding(dp(10), dp(9), dp(10), dp(9));
        binding.sectionsContainer.addView(text, params(3));
    }

    private LinearLayout.LayoutParams params(int top) {
        LinearLayout.LayoutParams params = new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT);
        params.topMargin = dp(top);
        return params;
    }

    private static String first(JsonObject item, String... keys) {
        for (String key : keys) {
            String value = Jsons.string(item, key);
            if (!value.isEmpty()) return value;
        }
        return "";
    }

    private static String fallback(String value, String fallback) { return value == null || value.isEmpty() ? fallback : value; }
    private static boolean isRelatedUserGroup(String groupName) {
        return "好友".equals(groupName) || "关注的人".equals(groupName) || "粉丝".equals(groupName);
    }
    private int dp(int value) { return Math.round(value * getResources().getDisplayMetrics().density); }

    @Override protected void onDestroy() {
        if (request != null) request.cancel();
        binding = null;
        super.onDestroy();
    }
}
