package xyz.jjmxg.yiyunying.ui.social;

import android.content.Context;
import android.content.Intent;
import android.os.Bundle;
import android.text.Editable;
import android.text.TextWatcher;
import android.view.Gravity;
import android.view.View;
import android.view.ViewGroup;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.TextView;

import androidx.activity.result.ActivityResultLauncher;
import androidx.activity.result.contract.ActivityResultContracts;
import androidx.annotation.NonNull;

import com.google.android.material.chip.Chip;
import com.google.android.material.shape.ShapeAppearanceModel;
import com.google.android.material.snackbar.Snackbar;
import com.google.android.material.imageview.ShapeableImageView;
import com.google.gson.JsonArray;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;
import com.google.gson.JsonParser;

import java.util.Arrays;
import java.util.LinkedHashMap;
import java.util.LinkedHashSet;
import java.util.Locale;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.core.AppAccess;
import xyz.jjmxg.yiyunying.core.RuntimeLanguage;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.data.api.RequestHandle;
import xyz.jjmxg.yiyunying.databinding.ActivityRoomCreateBinding;
import xyz.jjmxg.yiyunying.ui.chat.ChatActivity;
import xyz.jjmxg.yiyunying.ui.common.ImageLoader;
import xyz.jjmxg.yiyunying.ui.common.SystemInsetActivity;

public final class RoomCreateActivity extends SystemInsetActivity {
    private static final String EXTRA_ROOM_KIND = "room_kind";
    private static final String KIND_GROUP = "group";
    private static final String KIND_CHATROOM = "chat_room";
    private static final String STATE_FRIENDS = "friends";
    private static final String STATE_TAGS = "tags";
    private static final int MAX_INITIAL_MEMBERS = 99;
    private static final int MAX_TAGS = 6;
    private static final String[] FIXED_TAGS = {
        "生活", "同城", "兴趣", "游戏", "学习", "工作",
        "运动", "音乐", "影视", "科技", "交友", "摄影"
    };

    private ActivityRoomCreateBinding binding;
    private final LinkedHashMap<Long, JsonObject> selectedFriends = new LinkedHashMap<>();
    private final LinkedHashSet<String> selectedTags = new LinkedHashSet<>();
    private RequestHandle createRequest;
    private RequestHandle feedbackRequest;
    private boolean working;

    private final ActivityResultLauncher<Intent> friendPicker = registerForActivityResult(
        new ActivityResultContracts.StartActivityForResult(), result -> {
            if (binding == null || result.getResultCode() != RESULT_OK || result.getData() == null) return;
            String serialized = result.getData().getStringExtra(SocialDirectoryActivity.EXTRA_SELECTED_ITEMS);
            if (serialized == null || serialized.trim().isEmpty()) return;
            try {
                JsonArray values = JsonParser.parseString(serialized).getAsJsonArray();
                selectedFriends.clear();
                for (JsonElement element : values) {
                    if (!element.isJsonObject()) continue;
                    JsonObject friend = element.getAsJsonObject();
                    long userId = Jsons.longValue(friend, "user_id");
                    if (userId > 0) selectedFriends.put(userId, friend.deepCopy());
                }
                renderFriends();
            } catch (RuntimeException exception) {
                showMessage("好友选择结果无法读取，请重新选择");
            }
        });

    public static void open(Context context, boolean chatroom) {
        context.startActivity(new Intent(context, RoomCreateActivity.class)
            .putExtra(EXTRA_ROOM_KIND, chatroom ? KIND_CHATROOM : KIND_GROUP));
    }

    @Override protected void onCreate(Bundle state) {
        super.onCreate(state);
        restoreState(state);
        binding = ActivityRoomCreateBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());

        boolean chatroom = isChatroom();
        binding.toolbar.setNavigationOnClickListener(view -> finish());
        binding.toolbar.setTitle(chatroom ? "新建聊天室" : "新建群聊");
        binding.typeTitle.setText(chatroom ? "快速新建聊天室" : "快速新建群聊");
        binding.typeSummary.setText(chatroom
            ? "选择好友、标签和加入方式，创建后立即进入聊天室。"
            : "选择一位或多位好友，创建后首批成员会直接加入群聊。");
        binding.nameLayout.setHint(chatroom ? "聊天室名称" : "群聊名称");
        binding.descriptionLayout.setHint(chatroom ? "聊天室介绍（选填）" : "群聊介绍（选填）");
        binding.createButton.setText(chatroom ? "创建聊天室" : "创建群聊");
        binding.joinModeGroup.check(chatroom ? R.id.joinOpenButton : R.id.joinApprovalButton);

        binding.chooseFriendsButton.setOnClickListener(view -> chooseFriends());
        binding.customTagButton.setOnClickListener(view -> useCustomTag());
        binding.legacyButton.setOnClickListener(view -> {
            if (chatroom) SocialDirectoryActivity.openBasicCreateChatroom(this);
            else SocialDirectoryActivity.openBasicCreateGroup(this);
            finish();
        });
        binding.createButton.setOnClickListener(view -> create());
        binding.tagSearchInput.addTextChangedListener(new TextWatcher() {
            @Override public void beforeTextChanged(CharSequence value, int start, int count, int after) { }
            @Override public void onTextChanged(CharSequence value, int start, int before, int count) {
                renderSuggestedTags(value == null ? "" : value.toString());
            }
            @Override public void afterTextChanged(Editable value) { }
        });
        binding.tagSearchInput.setOnEditorActionListener((view, actionId, event) -> {
            if (binding.customTagButton.getVisibility() == View.VISIBLE) {
                useCustomTag();
                return true;
            }
            return false;
        });
        renderFriends();
        renderSelectedTags();
        renderSuggestedTags("");
    }

    private void chooseFriends() {
        long[] preselected = new long[selectedFriends.size()];
        int index = 0;
        for (long userId : selectedFriends.keySet()) preselected[index++] = userId;
        friendPicker.launch(SocialDirectoryActivity.pickFriendsIntent(
            this,
            MAX_INITIAL_MEMBERS,
            isChatroom() ? "选择聊天室首批成员" : "选择群聊首批成员",
            new long[0],
            "该好友暂时不可选择",
            preselected,
            true
        ));
    }

    private void renderFriends() {
        if (binding == null) return;
        binding.selectedPeople.removeAllViews();
        binding.selectedCount.setText(selectedFriends.isEmpty()
            ? "尚未选择好友，可创建后再邀请"
            : "已选 " + selectedFriends.size() + " 位好友，点击头像可取消");
        binding.chooseFriendsButton.setText(selectedFriends.isEmpty()
            ? "从好友列表选择"
            : "调整首批成员（" + selectedFriends.size() + "）");
        for (JsonObject friend : selectedFriends.values()) {
            long userId = Jsons.longValue(friend, "user_id");
            LinearLayout item = new LinearLayout(this);
            item.setOrientation(LinearLayout.VERTICAL);
            item.setGravity(Gravity.CENTER_HORIZONTAL);
            item.setPadding(dp(2), 0, dp(2), 0);

            ShapeableImageView avatar = new ShapeableImageView(this);
            avatar.setScaleType(ImageView.ScaleType.CENTER_CROP);
            avatar.setShapeAppearanceModel(ShapeAppearanceModel.builder()
                .setAllCornerSizes(dp(23)).build());
            ImageLoader.get().load(
                ImageLoader.get().absoluteUrl(this, Jsons.string(friend, "avatar")),
                avatar,
                R.drawable.ic_person
            );
            item.addView(avatar, new LinearLayout.LayoutParams(dp(46), dp(46)));

            TextView name = new TextView(this);
            RuntimeLanguage.setDynamicText(name, friendName(friend));
            name.setTextSize(11f);
            name.setTextColor(getColor(R.color.on_surface_variant));
            name.setGravity(Gravity.CENTER);
            name.setMaxLines(1);
            item.addView(name, new LinearLayout.LayoutParams(dp(68), ViewGroup.LayoutParams.WRAP_CONTENT));

            item.setContentDescription("取消选择 " + friendName(friend));
            item.setOnClickListener(view -> {
                selectedFriends.remove(userId);
                renderFriends();
            });
            LinearLayout.LayoutParams params = new LinearLayout.LayoutParams(dp(72), ViewGroup.LayoutParams.WRAP_CONTENT);
            params.setMarginEnd(dp(4));
            binding.selectedPeople.addView(item, params);
        }
    }

    private void renderSuggestedTags(String rawQuery) {
        if (binding == null) return;
        String query = normalizeTag(rawQuery);
        binding.suggestedTags.removeAllViews();
        boolean exactFixed = false;
        for (String tag : FIXED_TAGS) {
            if (!query.isEmpty() && !tag.toLowerCase(Locale.ROOT).contains(query.toLowerCase(Locale.ROOT))) continue;
            if (tag.equalsIgnoreCase(query)) exactFixed = true;
            Chip chip = new Chip(this);
            chip.setText(tag);
            chip.setCheckable(true);
            chip.setChecked(selectedTags.contains(tag));
            chip.setMinHeight(dp(44));
            chip.setOnCheckedChangeListener((button, checked) -> {
                if (checked) {
                    if (!addTag(tag)) button.setChecked(false);
                } else {
                    selectedTags.remove(tag);
                    renderSelectedTags();
                }
            });
            binding.suggestedTags.addView(chip);
        }
        boolean canUseCustom = !query.isEmpty() && !exactFixed && !selectedTags.contains(query);
        binding.customTagButton.setVisibility(canUseCustom ? View.VISIBLE : View.GONE);
        if (canUseCustom) binding.customTagButton.setText("临时使用“" + query + "”并申请收录");
    }

    private boolean addTag(String value) {
        String tag = normalizeTag(value);
        if (tag.isEmpty()) return false;
        if (selectedTags.contains(tag)) return true;
        if (selectedTags.size() >= MAX_TAGS) {
            showMessage("最多选择 " + MAX_TAGS + " 个标签");
            return false;
        }
        selectedTags.add(tag);
        renderSelectedTags();
        return true;
    }

    private void renderSelectedTags() {
        if (binding == null) return;
        binding.selectedTags.removeAllViews();
        if (selectedTags.isEmpty()) {
            Chip empty = new Chip(this);
            empty.setText("暂无标签");
            empty.setEnabled(false);
            binding.selectedTags.addView(empty);
            return;
        }
        for (String tag : selectedTags) {
            Chip chip = new Chip(this);
            chip.setText(tag);
            chip.setCloseIconVisible(true);
            chip.setMinHeight(dp(44));
            chip.setOnCloseIconClickListener(view -> {
                selectedTags.remove(tag);
                renderSelectedTags();
                renderSuggestedTags(text(binding.tagSearchInput));
            });
            binding.selectedTags.addView(chip);
        }
    }

    private void useCustomTag() {
        String tag = normalizeTag(text(binding.tagSearchInput));
        if (!addTag(tag)) return;
        binding.tagSearchInput.setText("");
        requestTagReview(tag);
    }

    private void requestTagReview(String tag) {
        if (feedbackRequest != null) feedbackRequest.cancel();
        JsonObject body = new JsonObject();
        body.addProperty("type", "chat_room_tag");
        body.addProperty("title", (isChatroom() ? "聊天室" : "群聊") + "标签申请：" + tag);
        body.addProperty("content", "申请收录标签“" + tag + "”。该标签已在本次创建中临时使用，请审核是否加入固定标签库。");
        feedbackRequest = AppAccess.from(this).repository().post("/api/user/feedbacks", body, result -> {
            feedbackRequest = null;
            if (binding == null) return;
            showMessage(result.isSuccessful()
                ? "已临时使用“" + tag + "”，收录申请已提交"
                : "已临时使用“" + tag + "”，收录申请暂未提交成功");
        });
    }

    private void create() {
        if (working || binding == null) return;
        binding.nameLayout.setError(null);
        String name = text(binding.nameInput);
        if (name.isEmpty()) {
            binding.nameLayout.setError("请输入" + (isChatroom() ? "聊天室" : "群聊") + "名称");
            binding.nameInput.requestFocus();
            return;
        }
        JsonObject body = new JsonObject();
        body.addProperty("name", name);
        body.addProperty("description", text(binding.descriptionInput));
        body.addProperty("room_kind", isChatroom() ? KIND_CHATROOM : KIND_GROUP);
        body.addProperty("join_mode", joinMode());
        JsonArray tags = new JsonArray();
        for (String tag : selectedTags) tags.add(tag);
        body.add("tags", tags);
        JsonArray members = new JsonArray();
        for (long userId : selectedFriends.keySet()) members.add(userId);
        body.add("initial_member_ids", members);

        setWorking(true, "正在创建并校验首批成员");
        createRequest = AppAccess.from(this).repository().post("/api/user/chat-rooms", body, result -> {
            createRequest = null;
            if (binding == null) return;
            if (!result.isSuccessful()) {
                setWorking(false, result.message().isEmpty() ? "创建失败，请稍后重试" : result.message());
                showMessage(binding.statusText.getText().toString());
                return;
            }
            JsonObject data = result.dataObject();
            JsonObject room = data.has("room") && data.get("room").isJsonObject()
                ? data.getAsJsonObject("room") : data;
            long roomId = Jsons.longValue(room, "id");
            String roomName = Jsons.string(room, "name");
            if (roomName.isEmpty()) roomName = name;
            setWorking(false, result.message().isEmpty() ? "创建成功" : result.message());
            if (roomId > 0) ChatActivity.openRoom(this, roomId, roomName);
            else SocialDirectoryActivity.openRooms(this);
            finish();
        });
    }

    private String joinMode() {
        int checked = binding.joinModeGroup.getCheckedButtonId();
        if (checked == R.id.joinInviteButton) return "invite";
        if (checked == R.id.joinOpenButton) return "open";
        return "approval";
    }

    private void setWorking(boolean value, String status) {
        working = value;
        binding.progress.setVisibility(value ? View.VISIBLE : View.INVISIBLE);
        binding.createButton.setEnabled(!value);
        binding.legacyButton.setEnabled(!value);
        binding.chooseFriendsButton.setEnabled(!value);
        binding.statusText.setText(status);
    }

    private void restoreState(Bundle state) {
        if (state == null) return;
        try {
            JsonArray friends = JsonParser.parseString(state.getString(STATE_FRIENDS, "[]")).getAsJsonArray();
            for (JsonElement element : friends) {
                if (!element.isJsonObject()) continue;
                JsonObject friend = element.getAsJsonObject();
                long userId = Jsons.longValue(friend, "user_id");
                if (userId > 0) selectedFriends.put(userId, friend.deepCopy());
            }
            JsonArray tags = JsonParser.parseString(state.getString(STATE_TAGS, "[]")).getAsJsonArray();
            for (JsonElement element : tags) if (element.isJsonPrimitive()) addRestoredTag(element.getAsString());
        } catch (RuntimeException ignored) { }
    }

    private void addRestoredTag(String value) {
        String tag = normalizeTag(value);
        if (!tag.isEmpty() && selectedTags.size() < MAX_TAGS) selectedTags.add(tag);
    }

    @Override protected void onSaveInstanceState(@NonNull Bundle outState) {
        JsonArray friends = new JsonArray();
        for (JsonObject friend : selectedFriends.values()) friends.add(friend.deepCopy());
        JsonArray tags = new JsonArray();
        for (String tag : selectedTags) tags.add(tag);
        outState.putString(STATE_FRIENDS, friends.toString());
        outState.putString(STATE_TAGS, tags.toString());
        super.onSaveInstanceState(outState);
    }

    @Override protected void onDestroy() {
        if (createRequest != null) createRequest.cancel();
        if (feedbackRequest != null) feedbackRequest.cancel();
        binding = null;
        super.onDestroy();
    }

    private boolean isChatroom() {
        return KIND_CHATROOM.equals(getIntent().getStringExtra(EXTRA_ROOM_KIND));
    }

    private String friendName(JsonObject friend) {
        for (String key : Arrays.asList("remark", "nickname", "account", "uid")) {
            String value = Jsons.string(friend, key);
            if (!value.isEmpty()) return value;
        }
        return "好友";
    }

    private String normalizeTag(String value) {
        if (value == null) return "";
        String tag = value.trim().replace("#", "").replaceAll("\\s+", " ");
        return tag.length() > 16 ? tag.substring(0, 16) : tag;
    }

    private String text(TextView view) {
        return view.getText() == null ? "" : view.getText().toString().trim();
    }

    private int dp(int value) {
        return Math.round(value * getResources().getDisplayMetrics().density);
    }

    private void showMessage(String value) {
        if (binding != null) Snackbar.make(binding.getRoot(), value, Snackbar.LENGTH_LONG).show();
    }
}