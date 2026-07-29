package xyz.jjmxg.yiyunying.ui.social;

import android.content.Context;
import android.content.Intent;
import android.os.Bundle;
import android.text.TextUtils;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.view.inputmethod.EditorInfo;
import android.widget.ArrayAdapter;
import android.widget.EditText;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.Spinner;
import android.widget.TextView;

import androidx.annotation.NonNull;
import androidx.recyclerview.widget.DiffUtil;
import androidx.recyclerview.widget.LinearLayoutManager;
import androidx.recyclerview.widget.RecyclerView;

import com.google.android.material.chip.Chip;
import xyz.jjmxg.yiyunying.ui.common.YiyunyingDialogBuilder;
import com.google.android.material.imageview.ShapeableImageView;
import com.google.android.material.snackbar.Snackbar;
import com.google.android.material.shape.ShapeAppearanceModel;
import com.google.gson.JsonArray;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;
import com.google.gson.JsonParser;

import java.util.ArrayList;
import java.util.HashSet;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.Set;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.core.AppAccess;
import xyz.jjmxg.yiyunying.core.RuntimeLanguage;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.data.api.RequestHandle;
import xyz.jjmxg.yiyunying.databinding.ActivitySocialDirectoryBinding;
import xyz.jjmxg.yiyunying.databinding.ItemSocialDirectoryBinding;
import xyz.jjmxg.yiyunying.ui.chat.ChatActivity;
import xyz.jjmxg.yiyunying.ui.chat.GroupSpaceActivity;
import xyz.jjmxg.yiyunying.ui.common.ImageLoader;
import xyz.jjmxg.yiyunying.ui.common.RecordDetailDialog;
import xyz.jjmxg.yiyunying.ui.profile.UserProfileActivity;

public final class SocialDirectoryActivity extends xyz.jjmxg.yiyunying.ui.common.SystemInsetActivity {
    private static final String EXTRA_MODE = "mode";
    private static final String MODE_FRIENDS = "friends";
    private static final String MODE_ROOMS = "rooms";
    public static final String EXTRA_PICK_MODE = "pick_mode";
    public static final String EXTRA_PICK_MAX = "pick_max";
    public static final String EXTRA_PICK_TITLE = "pick_title";
    public static final String EXTRA_EXCLUDED_USER_IDS = "excluded_user_ids";
    public static final String EXTRA_EXCLUDED_REASON = "excluded_reason";
    public static final String EXTRA_SELECTED_ITEMS = "selected_items";
    private static final String STATE_SELECTED_ITEMS = "selected_items_state";
    private ActivitySocialDirectoryBinding binding;
    private final List<JsonObject> items = new ArrayList<>();
    private final List<JsonObject> groups = new ArrayList<>();
    private final LinkedHashMap<Long, JsonObject> selectedItems = new LinkedHashMap<>();
    private final Set<Long> excludedUserIds = new HashSet<>();
    private DirectoryAdapter adapter;
    private RequestHandle request;
    private RequestHandle actionRequest;
    private long selectedGroup = -1;
    private boolean pickerMode;
    private int pickerMax = 1;
    private String excludedReason = "该好友不可选择";

    public static void openFriends(Context context) {
        context.startActivity(new Intent(context, SocialDirectoryActivity.class).putExtra(EXTRA_MODE, MODE_FRIENDS));
    }

    public static void openRooms(Context context) {
        context.startActivity(new Intent(context, SocialDirectoryActivity.class).putExtra(EXTRA_MODE, MODE_ROOMS));
    }

    public static Intent pickFriendsIntent(Context context, int max, String title, long[] excludedUserIds) {
        return pickFriendsIntent(context, max, title, excludedUserIds, "该好友已经在群聊中");
    }

    public static Intent pickFriendsIntent(
        Context context,
        int max,
        String title,
        long[] excludedUserIds,
        String excludedReason
    ) {
        return new Intent(context, SocialDirectoryActivity.class)
            .putExtra(EXTRA_MODE, MODE_FRIENDS)
            .putExtra(EXTRA_PICK_MODE, true)
            .putExtra(EXTRA_PICK_MAX, Math.max(1, max))
            .putExtra(EXTRA_PICK_TITLE, title == null ? "选择好友" : title)
            .putExtra(EXTRA_EXCLUDED_USER_IDS, excludedUserIds == null ? new long[0] : excludedUserIds)
            .putExtra(EXTRA_EXCLUDED_REASON,
                excludedReason == null || excludedReason.trim().isEmpty() ? "该好友不可选择" : excludedReason.trim());
    }

    public static Intent pickRoomsIntent(Context context, int max, String title, long[] excludedRoomIds) {
        return new Intent(context, SocialDirectoryActivity.class)
            .putExtra(EXTRA_MODE, MODE_ROOMS)
            .putExtra(EXTRA_PICK_MODE, true)
            .putExtra(EXTRA_PICK_MAX, Math.max(1, max))
            .putExtra(EXTRA_PICK_TITLE, title == null ? "选择群聊或聊天室" : title)
            .putExtra(EXTRA_EXCLUDED_USER_IDS, excludedRoomIds == null ? new long[0] : excludedRoomIds)
            .putExtra(EXTRA_EXCLUDED_REASON, "该群聊或聊天室不可选择");
    }

    @Override protected void onCreate(Bundle state) {
        super.onCreate(state);
        pickerMode = getIntent().getBooleanExtra(EXTRA_PICK_MODE, false);
        pickerMax = Math.max(1, getIntent().getIntExtra(EXTRA_PICK_MAX, 1));
        String requestedExcludedReason = getIntent().getStringExtra(EXTRA_EXCLUDED_REASON);
        if (requestedExcludedReason != null && !requestedExcludedReason.trim().isEmpty()) {
            excludedReason = requestedExcludedReason.trim();
        }
        for (long userId : getIntent().getLongArrayExtra(EXTRA_EXCLUDED_USER_IDS) == null
            ? new long[0] : getIntent().getLongArrayExtra(EXTRA_EXCLUDED_USER_IDS)) excludedUserIds.add(userId);
        restoreSelection(state);
        binding = ActivitySocialDirectoryBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());
        binding.toolbar.setNavigationOnClickListener(view -> finish());
        binding.toolbar.setTitle(pickerMode ? getIntent().getStringExtra(EXTRA_PICK_TITLE) : (isFriends() ? "好友管理" : "群聊管理"));
        binding.actionButton.setText(pickerMode ? "完成" : (isFriends() ? "添加好友" : "创建群聊"));
        binding.actionButton.setIconResource(pickerMode ? R.drawable.ic_send : R.drawable.ic_add);
        binding.searchLayout.setHint(isFriends() ? "搜索 UID、昵称或备注" : "搜索群号、群名、备注或标签");
        binding.manageGroupsButton.setVisibility(pickerMode ? View.GONE : View.VISIBLE);
        binding.selectedBar.setVisibility(pickerMode ? View.VISIBLE : View.GONE);
        adapter = new DirectoryAdapter();
        binding.recycler.setLayoutManager(new LinearLayoutManager(this));
        binding.recycler.setHasFixedSize(true);
        binding.recycler.setItemViewCacheSize(12);
        binding.recycler.setAdapter(adapter);
        binding.swipeRefresh.setOnRefreshListener(this::loadAll);
        binding.searchLayout.setEndIconOnClickListener(view -> loadItems());
        binding.searchInput.setOnEditorActionListener((view, action, event) -> {
            if (action == EditorInfo.IME_ACTION_SEARCH) { loadItems(); return true; }
            return false;
        });
        binding.manageGroupsButton.setOnClickListener(view -> manageGroups());
        binding.actionButton.setOnClickListener(view -> {
            if (pickerMode) finishPicker();
            else if (isFriends()) friendActions();
            else createRoom();
        });
        renderSelection();
        loadAll();
    }

    private void loadAll() {
        if (request != null) request.cancel();
        binding.progress.setVisibility(View.VISIBLE);
        loadCachedItems();
        request = AppAccess.from(this).repository().get(groupPath(), new LinkedHashMap<>(), result -> {
            request = null;
            if (binding == null) return;
            if (!result.isSuccessful()) { finishLoad(result.message()); return; }
            groups.clear(); groups.addAll(result.objectItems());
            renderGroupChips();
            loadItems();
        });
    }

    private void loadItems() {
        if (request != null) request.cancel();
        binding.progress.setVisibility(View.VISIBLE);
        Map<String, String> query = new LinkedHashMap<>();
        String keyword = binding.searchInput.getText() == null ? "" : binding.searchInput.getText().toString().trim();
        if (!keyword.isEmpty()) query.put("keyword", keyword);
        if (selectedGroup >= 0) query.put("group_id", String.valueOf(selectedGroup));
        if (!isFriends()) query.put("limit", "200");
        request = AppAccess.from(this).repository().get(itemPath(), query, result -> {
            request = null;
            if (binding == null) return;
            binding.progress.setVisibility(View.INVISIBLE);
            binding.swipeRefresh.setRefreshing(false);
            if (!result.isSuccessful()) { toast(result.message().isEmpty() ? "内容加载失败" : result.message()); return; }
            List<JsonObject> next = result.objectItems();
            if (pickerMode) {
                for (JsonObject item : next) {
                    long itemId = selectionId(item);
                    if (selectedItems.containsKey(itemId)) selectedItems.put(itemId, item.deepCopy());
                }
                renderSelection();
            }
            adapter.submit(next);
            binding.toolbar.setSubtitle(isFriends()
                ? "当前分组 " + items.size() + " 位好友"
                : "当前分组 " + items.size() + " 个群聊");
            binding.emptyText.setVisibility(items.isEmpty() ? View.VISIBLE : View.GONE);
            binding.emptyText.setText(isFriends() ? "当前分组还没有好友" : "当前分组还没有群聊");
        });
    }

    private void loadCachedItems() {
        Map<String, String> query = new LinkedHashMap<>();
        String keyword = binding.searchInput.getText() == null ? "" : binding.searchInput.getText().toString().trim();
        if (!keyword.isEmpty()) query.put("keyword", keyword);
        if (selectedGroup >= 0) query.put("group_id", String.valueOf(selectedGroup));
        if (!isFriends()) query.put("limit", "200");
        AppAccess.from(this).repository().getCached(itemPath(), query, cached -> {
            if (binding == null || isFinishing() || isDestroyed() || !cached.isSuccessful()) return;
            List<JsonObject> cachedItems = cached.objectItems();
            adapter.submit(cachedItems);
            binding.emptyText.setVisibility(cachedItems.isEmpty() ? View.VISIBLE : View.GONE);
            binding.progress.setVisibility(View.INVISIBLE);
        });
    }

    private void renderGroupChips() {
        binding.groupChips.removeAllViews();
        addGroupChip("全部", -1, false);
        addGroupChip("未分组", 0, false);
        for (JsonObject group : groups) addGroupChip(
            Jsons.string(group, "name") + " " + groupCount(group),
            Jsons.longValue(group, "id"),
            true
        );
    }

    private void addGroupChip(String name, long id, boolean dynamic) {
        Chip chip = new Chip(this);
        chip.setText(name); chip.setCheckable(true); chip.setTag(id); chip.setChecked(selectedGroup == id);
        if (dynamic) RuntimeLanguage.protectDynamicText(chip);
        chip.setOnClickListener(view -> { selectedGroup = (long) view.getTag(); loadItems(); });
        binding.groupChips.addView(chip);
    }

    private long groupCount(JsonObject group) {
        return Jsons.longValue(group, isFriends() ? "friend_count" : "room_count");
    }

    private void manageGroups() {
        String[] labels = new String[groups.size() + 1];
        labels[0] = "新建分组";
        for (int i = 0; i < groups.size(); i++) labels[i + 1] = Jsons.string(groups.get(i), "name") + " · " + groupCount(groups.get(i)) + " 项";
        new YiyunyingDialogBuilder(this).setTitle(isFriends() ? "好友分组" : "群聊分组")
            .setItems(labels, (dialog, which) -> { if (which == 0) groupEditor(null); else groupActions(groups.get(which - 1)); })
            .setNegativeButton("取消", null).show();
    }

    private void groupActions(JsonObject group) {
        new YiyunyingDialogBuilder(this).setBusinessTitle(Jsons.string(group, "name"))
            .setItems(new String[]{"重命名", "删除分组"}, (dialog, which) -> {
                if (which == 0) groupEditor(group); else confirmDeleteGroup(group);
            }).setNegativeButton("取消", null).show();
    }

    private void groupEditor(JsonObject group) {
        EditText input = input("分组名称");
        if (group != null) input.setText(Jsons.string(group, "name"));
        new YiyunyingDialogBuilder(this).setTitle(group == null ? "新建分组" : "重命名分组").setView(wrap(input))
            .setPositiveButton("保存", (dialog, which) -> {
                String name = input.getText().toString().trim();
                if (name.isEmpty()) { toast("请输入分组名称"); return; }
                JsonObject body = new JsonObject(); body.addProperty("name", name);
                if (group == null) post(groupPath(), body, "分组已创建");
                else put(groupPath() + "/" + Jsons.longValue(group, "id"), body, "分组已更新");
            }).setNegativeButton("取消", null).show();
    }

    private void confirmDeleteGroup(JsonObject group) {
        new YiyunyingDialogBuilder(this).setTitle("删除分组").setMessage("只删除分组，不会删除其中的好友或群聊。")
            .setPositiveButton("删除", (dialog, which) -> delete(groupPath() + "/" + Jsons.longValue(group, "id"), "分组已删除"))
            .setNegativeButton("取消", null).show();
    }

    private void friendActions() {
        AddFriendActivity.open(this);
    }

    private void toggleSelection(JsonObject item, int adapterPosition) {
        long itemId = selectionId(item);
        if (itemId <= 0) { toast("这个" + selectionEntity() + "缺少有效编号"); return; }
        if (excludedUserIds.contains(itemId)) { toast(excludedReason); return; }
        if (selectedItems.containsKey(itemId)) selectedItems.remove(itemId);
        else {
            if (selectedItems.size() >= pickerMax) { toast("最多选择 " + pickerMax + " " + selectionUnit()); return; }
            selectedItems.put(itemId, item.deepCopy());
        }
        if (adapterPosition >= 0 && adapterPosition < items.size()) adapter.notifyItemChanged(adapterPosition);
        renderSelection();
    }

    private void renderSelection() {
        if (binding == null || !pickerMode) return;
        binding.selectedPeople.removeAllViews();
        binding.selectedCount.setText("已选 " + selectedItems.size() + " " + selectionUnit()
            + " · 最多 " + pickerMax + " " + selectionUnit());
        binding.actionButton.setText(selectedItems.isEmpty() ? "完成" : "完成 (" + selectedItems.size() + ")");
        for (JsonObject item : selectedItems.values()) {
            LinearLayout person = new LinearLayout(this);
            person.setOrientation(LinearLayout.VERTICAL);
            person.setGravity(android.view.Gravity.CENTER_HORIZONTAL);
            person.setPadding(dp(2), 0, dp(2), 0);
            ShapeableImageView avatar = new ShapeableImageView(this);
            avatar.setScaleType(ImageView.ScaleType.CENTER_CROP);
            avatar.setShapeAppearanceModel(ShapeAppearanceModel.builder().setAllCornerSizes(dp(22)).build());
            ImageLoader.get().load(ImageLoader.get().absoluteUrl(this, selectionAvatar(item)), avatar, selectionPlaceholder());
            person.addView(avatar, new LinearLayout.LayoutParams(dp(44), dp(44)));
            TextView name = new TextView(this);
            RuntimeLanguage.setDynamicText(name, displayName(item));
            name.setTextSize(11f);
            name.setTextColor(getColor(R.color.on_surface_variant));
            name.setGravity(android.view.Gravity.CENTER);
            name.setEllipsize(TextUtils.TruncateAt.END);
            name.setMaxLines(1);
            person.addView(name, new LinearLayout.LayoutParams(dp(64), ViewGroup.LayoutParams.WRAP_CONTENT));
            long itemId = selectionId(item);
            person.setContentDescription("取消选择 " + displayName(item));
            person.setOnClickListener(view -> {
                selectedItems.remove(itemId);
                int position = items.indexOf(item);
                if (position >= 0) adapter.notifyItemChanged(position);
                renderSelection();
            });
            LinearLayout.LayoutParams params = new LinearLayout.LayoutParams(dp(68), ViewGroup.LayoutParams.WRAP_CONTENT);
            params.setMarginEnd(dp(4));
            binding.selectedPeople.addView(person, params);
        }
    }

    private void finishPicker() {
        if (selectedItems.isEmpty()) { toast("请至少选择一个" + selectionEntity()); return; }
        JsonArray values = new JsonArray();
        for (JsonObject item : selectedItems.values()) values.add(item.deepCopy());
        setResult(RESULT_OK, new Intent().putExtra(EXTRA_SELECTED_ITEMS, values.toString()));
        finish();
    }

    private void restoreSelection(Bundle state) {
        if (state == null) return;
        String serialized = state.getString(STATE_SELECTED_ITEMS, "");
        if (serialized.isEmpty()) return;
        try {
            JsonArray values = JsonParser.parseString(serialized).getAsJsonArray();
            for (JsonElement value : values) {
                if (!value.isJsonObject()) continue;
                JsonObject item = value.getAsJsonObject();
                long itemId = selectionId(item);
                if (itemId > 0) selectedItems.put(itemId, item.deepCopy());
            }
        } catch (RuntimeException ignored) { }
    }

    @Override protected void onSaveInstanceState(@NonNull Bundle outState) {
        JsonArray values = new JsonArray();
        for (JsonObject item : selectedItems.values()) values.add(item.deepCopy());
        outState.putString(STATE_SELECTED_ITEMS, values.toString());
        super.onSaveInstanceState(outState);
    }

    private void addFriend() {
        LinearLayout form = form(); EditText number = input("用户 UID"), message = input("验证消息"); form.addView(number); form.addView(message);
        new YiyunyingDialogBuilder(this).setTitle("添加好友").setView(form)
            .setPositiveButton("发送申请", (dialog, which) -> {
                String value = number.getText().toString().trim();
                if (value.isEmpty()) { toast("请输入用户 UID"); return; }
                JsonObject body = new JsonObject();
                body.addProperty("to_uid", value);
                body.addProperty("message", message.getText().toString().trim()); post("/api/user/friends/requests", body, "好友申请已发送");
            }).setNegativeButton("取消", null).show();
    }

    private void createRoom() {
        LinearLayout form = form(); EditText name = input("群名称"), description = input("群介绍"), tags = input("标签，用逗号分隔");
        form.addView(name); form.addView(description); form.addView(tags);
        new YiyunyingDialogBuilder(this).setTitle("创建群聊").setView(form)
            .setPositiveButton("创建", (dialog, which) -> {
                if (name.getText().toString().trim().isEmpty()) { toast("请输入群名称"); return; }
                JsonObject body = new JsonObject(); body.addProperty("name", name.getText().toString().trim()); body.addProperty("description", description.getText().toString().trim()); body.addProperty("join_mode", "approval");
                JsonArray values = new JsonArray(); for (String tag : tags.getText().toString().split("[,，]")) if (!tag.trim().isEmpty()) values.add(tag.trim()); body.add("tags", values);
                post(itemPath(), body, "群聊已创建");
            }).setNegativeButton("取消", null).show();
    }

    private void openItem(JsonObject item) {
        if (isFriends()) {
            ChatActivity.openPeer(this, Jsons.longValue(item, "user_id"), displayName(item));
            return;
        }
        if (bool(item, "joined")) ChatActivity.openRoom(this, Jsons.longValue(item, "id"), displayName(item));
        else confirmJoin(item);
    }

    private void showActions(JsonObject item) {
        if (isFriends()) {
            new YiyunyingDialogBuilder(this).setBusinessTitle(displayName(item)).setItems(new String[]{"发送消息", "查看个人主页", "修改备注与分组", "删除好友"}, (d, which) -> {
                if (which == 0) ChatActivity.openPeer(this, Jsons.longValue(item, "user_id"), displayName(item));
                else if (which == 1) UserProfileActivity.open(this, Jsons.longValue(item, "user_id"));
                else if (which == 2) editAssignment(item);
                else confirmDeleteFriend(item);
            }).setNegativeButton("取消", null).show();
        } else {
            List<String> actions = new ArrayList<>();
            actions.add(bool(item, "joined") ? "进入群聊" : "申请加入"); actions.add("查看群资料");
            if (bool(item, "joined")) actions.add("修改群备注与分组");
            new YiyunyingDialogBuilder(this).setBusinessTitle(displayName(item)).setItems(actions.toArray(new String[0]), (d, which) -> {
                if (which == 0) openItem(item);
                else if (which == 1) { if (bool(item, "joined")) GroupSpaceActivity.open(this, Jsons.longValue(item, "id"), displayName(item)); else RecordDetailDialog.show(this, "群聊资料", item); }
                else editAssignment(item);
            }).setNegativeButton("取消", null).show();
        }
    }

    private void editAssignment(JsonObject item) {
        LinearLayout form = form(); EditText remark = input(isFriends() ? "好友备注" : "群聊备注");
        remark.setText(Jsons.string(item, isFriends() ? "remark" : "user_remark"));
        Spinner spinner = new Spinner(this); List<String> names = new ArrayList<>(); names.add("未分组");
        int selected = 0; long current = Jsons.longValue(item, isFriends() ? "group_id" : "user_group_id");
        for (int i = 0; i < groups.size(); i++) { names.add(Jsons.string(groups.get(i), "name")); if (Jsons.longValue(groups.get(i), "id") == current) selected = i + 1; }
        spinner.setAdapter(new ArrayAdapter<>(this, android.R.layout.simple_spinner_dropdown_item, names)); spinner.setSelection(selected);
        form.addView(remark); form.addView(spinner, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, dp(52)));
        new YiyunyingDialogBuilder(this).setTitle("修改备注与分组").setView(form).setPositiveButton("保存", (dialog, which) -> {
            int position = spinner.getSelectedItemPosition(); long groupId = position <= 0 ? 0 : Jsons.longValue(groups.get(position - 1), "id");
            JsonObject body = new JsonObject(); body.addProperty("remark", remark.getText().toString().trim()); body.addProperty("group_id", groupId);
            String path = isFriends() ? "/api/user/friends/" + Jsons.longValue(item, "user_id") : "/api/user/chat-rooms/" + Jsons.longValue(item, "id") + "/user-settings";
            put(path, body, "备注与分组已更新");
        }).setNegativeButton("取消", null).show();
    }

    private void confirmJoin(JsonObject item) {
        EditText message = input("申请说明");
        new YiyunyingDialogBuilder(this).setTitle("申请加入 " + displayName(item)).setView(wrap(message))
            .setPositiveButton("提交申请", (dialog, which) -> { JsonObject body = new JsonObject(); body.addProperty("message", message.getText().toString().trim()); post("/api/user/chat-rooms/" + Jsons.longValue(item, "id") + "/join", body, "加群申请已提交"); })
            .setNegativeButton("取消", null).show();
    }

    private void confirmDeleteFriend(JsonObject item) {
        new YiyunyingDialogBuilder(this).setTitle("删除好友").setMessage("确定删除“" + displayName(item) + "”吗？聊天记录不会因此自动删除。")
            .setPositiveButton("删除", (dialog, which) -> delete("/api/user/friends/" + Jsons.longValue(item, "user_id"), "好友已删除"))
            .setNegativeButton("取消", null).show();
    }

    private void post(String path, JsonObject body, String fallback) { execute("post", path, body, fallback); }
    private void put(String path, JsonObject body, String fallback) { execute("put", path, body, fallback); }
    private void delete(String path, String fallback) { execute("delete", path, new JsonObject(), fallback); }
    private void execute(String method, String path, JsonObject body, String fallback) {
        if (actionRequest != null) return; binding.progress.setVisibility(View.VISIBLE);
        xyz.jjmxg.yiyunying.data.repository.YiyunyingRepository repository = AppAccess.from(this).repository();
        xyz.jjmxg.yiyunying.data.api.ApiCallback callback = result -> {
            actionRequest = null; if (binding == null) return; binding.progress.setVisibility(View.INVISIBLE);
            toast(result.isSuccessful() ? (result.message().isEmpty() ? fallback : result.message()) : (result.message().isEmpty() ? "操作失败" : result.message()));
            if (result.isSuccessful()) loadAll();
        };
        if ("put".equals(method)) actionRequest = repository.put(path, body, callback);
        else if ("delete".equals(method)) actionRequest = repository.delete(path, body, callback);
        else actionRequest = repository.post(path, body, callback);
    }

    private void finishLoad(String message) { binding.progress.setVisibility(View.INVISIBLE); binding.swipeRefresh.setRefreshing(false); toast(message.isEmpty() ? "分组加载失败" : message); }
    private void toast(String value) { Snackbar.make(binding.getRoot(), value, Snackbar.LENGTH_LONG).show(); }
    private boolean isFriends() { return !MODE_ROOMS.equals(getIntent().getStringExtra(EXTRA_MODE)); }
    private String groupPath() { return isFriends() ? "/api/user/friend-groups" : "/api/user/chat-room-groups"; }
    private String itemPath() { return isFriends() ? "/api/user/friends" : "/api/user/chat-rooms"; }
    private long selectionId(JsonObject item) { return Jsons.longValue(item, isFriends() ? "user_id" : "id"); }
    private String selectionAvatar(JsonObject item) { return Jsons.string(item, isFriends() ? "avatar" : "icon"); }
    private int selectionPlaceholder() { return isFriends() ? R.drawable.ic_person : R.drawable.ic_users; }
    private String selectionUnit() { return isFriends() ? "人" : "个"; }
    private String selectionEntity() { return isFriends() ? "好友" : "群聊或聊天室"; }
    private void openPickerProfile(JsonObject item) {
        long itemId = selectionId(item);
        if (isFriends()) UserProfileActivity.open(this, itemId);
        else if (bool(item, "joined")) GroupSpaceActivity.open(this, itemId, displayName(item));
        else showActions(item);
    }
    private String displayName(JsonObject item) { String remark = Jsons.string(item, isFriends() ? "remark" : "user_remark"); if (!remark.isEmpty()) return remark; String name = Jsons.string(item, isFriends() ? "nickname" : "name"); return name.isEmpty() ? Jsons.string(item, "account") : name; }
    private LinearLayout form() { LinearLayout form = new LinearLayout(this); form.setOrientation(LinearLayout.VERTICAL); form.setPadding(dp(22), dp(4), dp(22), 0); return form; }
    private LinearLayout wrap(View view) { LinearLayout form = form(); form.addView(view); return form; }
    private EditText input(String hint) { EditText input = new EditText(this); input.setHint(hint); input.setPadding(dp(8), dp(10), dp(8), dp(10)); return input; }
    private int dp(int value) { return Math.round(value * getResources().getDisplayMetrics().density); }
    private static boolean bool(JsonObject item, String key) { try { return item.has(key) && item.get(key).getAsBoolean(); } catch (RuntimeException ignored) { return false; } }

    @Override protected void onDestroy() { if (request != null) request.cancel(); if (actionRequest != null) actionRequest.cancel(); binding = null; super.onDestroy(); }

    private final class DirectoryAdapter extends RecyclerView.Adapter<DirectoryAdapter.Holder> {
        DirectoryAdapter() { setHasStableIds(true); }
        void submit(List<JsonObject> next) {
            List<JsonObject> previous = new ArrayList<>(items);
            if (sameContents(previous, next)) return;
            DiffUtil.DiffResult diff = DiffUtil.calculateDiff(new DiffUtil.Callback() {
                @Override public int getOldListSize() { return previous.size(); }
                @Override public int getNewListSize() { return next.size(); }
                @Override public boolean areItemsTheSame(int oldPosition, int newPosition) {
                    return selectionId(previous.get(oldPosition)) == selectionId(next.get(newPosition));
                }
                @Override public boolean areContentsTheSame(int oldPosition, int newPosition) {
                    return previous.get(oldPosition).equals(next.get(newPosition));
                }
            }, false);
            items.clear();
            items.addAll(next);
            diff.dispatchUpdatesTo(this);
        }
        private boolean sameContents(List<JsonObject> left, List<JsonObject> right) {
            if (left.size() != right.size()) return false;
            for (int index = 0; index < left.size(); index++) {
                if (!left.get(index).equals(right.get(index))) return false;
            }
            return true;
        }
        @NonNull @Override public Holder onCreateViewHolder(@NonNull ViewGroup parent, int type) { return new Holder(ItemSocialDirectoryBinding.inflate(LayoutInflater.from(parent.getContext()), parent, false)); }
        @Override public void onBindViewHolder(@NonNull Holder holder, int position) {
            JsonObject item = items.get(position);
            RuntimeLanguage.setDynamicText(holder.binding.title, displayName(item));
            if (isFriends()) {
                String nickname = Jsons.string(item, "nickname");
                String uid = Jsons.string(item, "uid");
                if (uid.isEmpty()) uid = Jsons.string(item, "public_no");
                RuntimeLanguage.setDynamicText(holder.binding.subtitle,
                    (nickname.isEmpty() ? Jsons.string(item, "account") : nickname) + " · UID " + uid);
                RuntimeLanguage.setDynamicText(holder.binding.metadata,
                    (Jsons.string(item, "group_name").isEmpty() ? "未分组" : Jsons.string(item, "group_name"))
                        + " · " + Jsons.string(item, "signature"));
                ImageLoader.get().load(ImageLoader.get().absoluteUrl(SocialDirectoryActivity.this, Jsons.string(item, "avatar")), holder.binding.avatar, R.drawable.ic_person);
            } else {
                holder.binding.subtitle.setText("群号 " + Jsons.longValue(item, "id") + " · " + Jsons.longValue(item, "member_count") + " 人");
                RuntimeLanguage.setDynamicText(holder.binding.metadata,
                    (Jsons.string(item, "user_group_name").isEmpty() ? "未分组" : Jsons.string(item, "user_group_name"))
                        + " · " + (bool(item, "joined") ? "已加入" : "可申请加入"));
                ImageLoader.get().load(ImageLoader.get().absoluteUrl(SocialDirectoryActivity.this, Jsons.string(item, "icon")), holder.binding.avatar, R.drawable.ic_users);
            }
            if (pickerMode) {
                long itemId = selectionId(item);
                boolean excluded = excludedUserIds.contains(itemId);
                holder.binding.moreButton.setVisibility(View.GONE);
                holder.binding.selectionCheck.setVisibility(View.VISIBLE);
                holder.binding.selectionCheck.setChecked(selectedItems.containsKey(itemId));
                holder.binding.selectionCheck.setEnabled(!excluded);
                holder.binding.getRoot().setAlpha(excluded ? 0.45f : 1f);
                holder.binding.getRoot().setOnClickListener(view ->
                    toggleSelection(item, holder.getBindingAdapterPosition()));
                holder.binding.avatar.setOnClickListener(view -> openPickerProfile(item));
                holder.binding.getRoot().setOnLongClickListener(view -> {
                    openPickerProfile(item);
                    return true;
                });
                return;
            }
            holder.binding.getRoot().setAlpha(1f);
            holder.binding.moreButton.setVisibility(View.VISIBLE);
            holder.binding.selectionCheck.setVisibility(View.GONE);
            holder.binding.getRoot().setOnClickListener(view -> openItem(item));
            holder.binding.avatar.setOnClickListener(view -> {
                if (isFriends()) UserProfileActivity.open(SocialDirectoryActivity.this, Jsons.longValue(item, "user_id"));
                else if (bool(item, "joined")) GroupSpaceActivity.open(SocialDirectoryActivity.this,
                    Jsons.longValue(item, "id"), displayName(item));
                else showActions(item);
            });
            holder.binding.getRoot().setOnLongClickListener(view -> { showActions(item); return true; });
            holder.binding.moreButton.setOnClickListener(view -> showActions(item));
        }
        @Override public long getItemId(int position) { return selectionId(items.get(position)); }
        @Override public int getItemCount() { return items.size(); }
        final class Holder extends RecyclerView.ViewHolder { final ItemSocialDirectoryBinding binding; Holder(ItemSocialDirectoryBinding value) { super(value.getRoot()); binding = value; } }
    }
}
