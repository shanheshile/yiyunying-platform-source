package xyz.jjmxg.yiyunying.ui.chat;

import android.content.Context;
import android.content.Intent;
import android.net.Uri;
import android.os.Bundle;
import android.view.View;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.TextView;

import androidx.activity.result.ActivityResultLauncher;
import androidx.activity.result.ActivityResult;
import androidx.activity.result.contract.ActivityResultContracts;

import com.google.android.material.button.MaterialButton;
import com.google.android.material.materialswitch.MaterialSwitch;
import com.google.android.material.progressindicator.LinearProgressIndicator;
import com.google.android.material.snackbar.Snackbar;
import com.google.android.material.textfield.TextInputEditText;
import com.google.android.material.appbar.MaterialToolbar;
import com.google.gson.JsonArray;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;
import com.google.gson.JsonParser;

import java.util.LinkedHashMap;
import java.util.ArrayList;
import java.util.List;
import java.util.Map;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.core.AppAccess;
import xyz.jjmxg.yiyunying.core.ChatBackgroundStore;
import xyz.jjmxg.yiyunying.core.RuntimeLanguage;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.data.api.RequestHandle;
import xyz.jjmxg.yiyunying.domain.chat.ContactCardIdentity;
import xyz.jjmxg.yiyunying.ui.common.ImageLoader;
import xyz.jjmxg.yiyunying.ui.common.SystemInsetActivity;
import xyz.jjmxg.yiyunying.ui.profile.UserProfileActivity;
import xyz.jjmxg.yiyunying.ui.social.SocialDirectoryActivity;
import xyz.jjmxg.yiyunying.ui.upload.MediaPickerActivity;

/** Visual, localized controls for one conversation and its relationship permissions. */
public final class ConversationPermissionActivity extends SystemInsetActivity {
    public static final String TYPE_PRIVATE = "private";
    public static final String TYPE_GROUP = "group";
    public static final String TYPE_CHAT_ROOM = "chat_room";

    private static final String EXTRA_TYPE = "conversation_type";
    private static final String EXTRA_TARGET_ID = "target_id";
    private static final String EXTRA_PEER_ID = "peer_id";
    private static final String EXTRA_TITLE = "title";
    private static final String EXTRA_BACKGROUND_IDENTITY = "background_identity";
    private static final String EXTRA_FROM_PROFILE = "from_profile";

    private LinearProgressIndicator progress;
    private ImageView avatar;
    private TextView profileTitle;
    private TextView profileSubtitle;
    private LinearLayout friendSection;
    private MaterialSwitch pinnedSwitch;
    private MaterialSwitch bottomedSwitch;
    private MaterialSwitch mutedSwitch;
    private MaterialSwitch hiddenSwitch;
    private MaterialSwitch specialCareSwitch;
    private MaterialSwitch onlyChatSwitch;
    private MaterialSwitch hideMyDynamicSwitch;
    private MaterialSwitch hideTheirDynamicSwitch;
    private MaterialSwitch blacklistSwitch;
    private TextInputEditText remarkInput;
    private TextInputEditText relationshipInput;
    private TextInputEditText clueInput;
    private TextView backgroundState;
    private MaterialButton recommendContactButton;
    private MaterialButton saveFriendButton;

    private RequestHandle centerRequest;
    private RequestHandle friendRequest;
    private RequestHandle blacklistRequest;
    private RequestHandle actionRequest;
    private long effectiveTargetId;
    private boolean loadingControls;
    private boolean originalBlacklisted;
    private int pendingLoads;
    private String friendGroupName = "";
    private String friendCreatedAt = "";
    private JsonObject friendProfile = new JsonObject();

    private final ActivityResultLauncher<Intent> backgroundPicker = registerForActivityResult(
        new ActivityResultContracts.StartActivityForResult(), this::setBackground);

    private final ActivityResultLauncher<Intent> recommendationRecipientPicker = registerForActivityResult(
        new ActivityResultContracts.StartActivityForResult(), result -> {
            if (result.getResultCode() != RESULT_OK || result.getData() == null) return;
            String serialized = clean(result.getData().getStringExtra(SocialDirectoryActivity.EXTRA_SELECTED_ITEMS));
            if (serialized.isEmpty()) return;
            ArrayList<JsonObject> recipients = new ArrayList<>();
            try {
                JsonElement parsed = JsonParser.parseString(serialized);
                if (parsed.isJsonArray()) {
                    for (JsonElement element : parsed.getAsJsonArray()) {
                        if (element.isJsonObject() && Jsons.longValue(element.getAsJsonObject(), "user_id") > 0L) {
                            recipients.add(element.getAsJsonObject().deepCopy());
                        }
                    }
                }
            } catch (RuntimeException ignored) { }
            if (recipients.isEmpty()) {
                showMessage(translated(R.string.recommend_contact_no_recipient));
                return;
            }
            sendRecommendedContact(recipients, 0, 0, 0);
        });

    public static void openPrivate(Context context, long conversationId, long peerId, String title, String backgroundIdentity) {
        context.startActivity(intent(context, TYPE_PRIVATE, conversationId, peerId, title, backgroundIdentity, false));
    }

    public static void openPrivateFromProfile(Context context, long conversationId, long peerId, String title,
                                              String backgroundIdentity) {
        context.startActivity(intent(context, TYPE_PRIVATE, conversationId, peerId, title, backgroundIdentity, true));
    }

    public static void openGroup(Context context, long roomId, String title, String backgroundIdentity) {
        context.startActivity(intent(context, TYPE_GROUP, roomId, 0L, title, backgroundIdentity, false));
    }

    public static void openChatRoom(Context context, long roomId, String title, String backgroundIdentity) {
        context.startActivity(intent(context, TYPE_CHAT_ROOM, roomId, 0L, title, backgroundIdentity, false));
    }

    private static Intent intent(Context context, String type, long targetId, long peerId, String title,
                                 String backgroundIdentity, boolean fromProfile) {
        return new Intent(context, ConversationPermissionActivity.class)
            .putExtra(EXTRA_TYPE, type)
            .putExtra(EXTRA_TARGET_ID, targetId)
            .putExtra(EXTRA_PEER_ID, peerId)
            .putExtra(EXTRA_TITLE, title)
            .putExtra(EXTRA_BACKGROUND_IDENTITY, backgroundIdentity)
            .putExtra(EXTRA_FROM_PROFILE, fromProfile);
    }

    @Override protected void onCreate(Bundle state) {
        super.onCreate(state);
        setContentView(R.layout.activity_conversation_permission);
        bindViews();
        effectiveTargetId = getIntent().getLongExtra(EXTRA_TARGET_ID, 0L);
        String title = clean(getIntent().getStringExtra(EXTRA_TITLE));
        if (title.isEmpty()) profileTitle.setText(R.string.profile);
        else profileTitle.setText(title);
        profileSubtitle.setText(isPrivate()
            ? R.string.private_chat_subtitle
            : (isChatRoom() ? R.string.chat_room_subtitle : R.string.group_chat_subtitle));
        avatar.setImageResource(isPrivate() ? R.drawable.ic_person : R.drawable.bg_group_avatar_placeholder);
        friendSection.setVisibility(isPrivate() && peerId() > 0L ? View.VISIBLE : View.GONE);
        configureActions();
        renderBackgroundState();
        loadAll();
    }

    private void bindViews() {
        MaterialToolbar toolbar = findViewById(R.id.toolbar);
        toolbar.setNavigationOnClickListener(view -> finish());
        toolbar.setTitle(isPrivate()
            ? "好友与聊天设置"
            : (isChatRoom() ? "聊天室设置" : "群聊设置"));
        progress = findViewById(R.id.progress);
        avatar = findViewById(R.id.avatar);
        profileTitle = findViewById(R.id.profile_title);
        profileSubtitle = findViewById(R.id.profile_subtitle);
        friendSection = findViewById(R.id.friend_section);
        pinnedSwitch = findViewById(R.id.pinned_switch);
        bottomedSwitch = findViewById(R.id.bottomed_switch);
        mutedSwitch = findViewById(R.id.muted_switch);
        hiddenSwitch = findViewById(R.id.hidden_switch);
        specialCareSwitch = findViewById(R.id.special_care_switch);
        onlyChatSwitch = findViewById(R.id.only_chat_switch);
        hideMyDynamicSwitch = findViewById(R.id.hide_my_dynamic_switch);
        hideTheirDynamicSwitch = findViewById(R.id.hide_their_dynamic_switch);
        blacklistSwitch = findViewById(R.id.blacklist_switch);
        remarkInput = findViewById(R.id.remark_input);
        relationshipInput = findViewById(R.id.relationship_input);
        clueInput = findViewById(R.id.clue_input);
        backgroundState = findViewById(R.id.background_state);
        recommendContactButton = findViewById(R.id.recommend_contact_button);
        saveFriendButton = findViewById(R.id.save_friend_button);
    }

    private void configureActions() {
        findViewById(R.id.profile_entry).setOnClickListener(view -> openProfile());
        pinnedSwitch.setOnCheckedChangeListener((button, checked) -> {
            if (loadingControls) return;
            if (checked) setCheckedSilently(bottomedSwitch, false);
            saveConversationPreference("is_pinned", checked);
        });
        bottomedSwitch.setOnCheckedChangeListener((button, checked) -> {
            if (loadingControls) return;
            if (checked) setCheckedSilently(pinnedSwitch, false);
            saveConversationPreference("is_bottomed", checked);
        });
        mutedSwitch.setOnCheckedChangeListener((button, checked) -> {
            if (!loadingControls) saveConversationPreference("is_muted", checked);
        });
        hiddenSwitch.setOnCheckedChangeListener((button, checked) -> {
            if (!loadingControls) saveConversationPreference("is_hidden", checked);
        });
        findViewById(R.id.choose_background_button).setOnClickListener(view ->
            backgroundPicker.launch(MediaPickerActivity.imageIntent(this, 1)));
        findViewById(R.id.reset_background_button).setOnClickListener(view -> {
            ChatBackgroundStore.clearConversation(this, backgroundIdentity());
            renderBackgroundState();
            showMessage(translated(R.string.background_reset));
        });
        findViewById(R.id.system_default_background_button).setOnClickListener(view -> {
            ChatBackgroundStore.setConversationSystemDefault(this, backgroundIdentity());
            renderBackgroundState();
            showMessage(translated(R.string.background_system_default_applied));
        });
        findViewById(R.id.auto_relationship_button).setOnClickListener(view -> generateRelationshipLabel());
        specialCareSwitch.setOnCheckedChangeListener((button, checked) -> refreshGeneratedClue());
        onlyChatSwitch.setOnCheckedChangeListener((button, checked) -> refreshGeneratedClue());
        recommendContactButton.setOnClickListener(view -> chooseRecommendationRecipients());
        saveFriendButton.setOnClickListener(view -> saveFriendPermissions());
    }

    private void loadAll() {
        pendingLoads = isPrivate() && peerId() > 0L ? 3 : 1;
        progress.setVisibility(View.VISIBLE);
        loadMessageCenter();
        if (isPrivate() && peerId() > 0L) {
            loadFriend();
            loadBlacklist();
        }
    }

    private void loadMessageCenter() {
        Map<String, String> query = new LinkedHashMap<>();
        query.put("include_hidden", "true");
        query.put("limit", "200");
        centerRequest = AppAccess.from(this).repository().get("/api/user/message-center", query, result -> {
            centerRequest = null;
            if (isFinishing() || isDestroyed()) return;
            if (result.isSuccessful()) {
                for (JsonObject item : items(result.dataObject())) {
                    if (!matchesConversation(item)) continue;
                    effectiveTargetId = Jsons.longValue(item, "target_id");
                    profileTitle.setText(displayName(item));
                    String image = ImageLoader.get().absoluteUrl(this, Jsons.string(item, "avatar"));
                    ImageLoader.get().load(image, avatar,
                        isPrivate() ? R.drawable.ic_person : R.drawable.bg_group_avatar_placeholder);
                    loadingControls = true;
                    pinnedSwitch.setChecked(bool(item, "is_pinned"));
                    bottomedSwitch.setChecked(bool(item, "is_bottomed"));
                    mutedSwitch.setChecked(bool(item, "is_muted"));
                    hiddenSwitch.setChecked(bool(item, "is_hidden"));
                    loadingControls = false;
                    break;
                }
            } else {
                showMessage(result.message().isEmpty() ? getString(R.string.network_error) : result.message());
            }
            loadFinished();
        });
    }

    private void loadFriend() {
        friendRequest = AppAccess.from(this).repository().get("/api/user/friends", new LinkedHashMap<>(), result -> {
            friendRequest = null;
            if (isFinishing() || isDestroyed()) return;
            if (result.isSuccessful()) {
                for (JsonObject item : items(result.dataObject())) {
                    if (Jsons.longValue(item, "user_id") != peerId()) continue;
                    friendProfile = item.deepCopy();
                    remarkInput.setText(Jsons.string(item, "remark"));
                    relationshipInput.setText(Jsons.string(item, "relationship_label"));
                    friendGroupName = clean(Jsons.string(item, "group_name"));
                    friendCreatedAt = clean(Jsons.string(item, "created_at"));
                    specialCareSwitch.setChecked(bool(item, "special_care"));
                    onlyChatSwitch.setChecked(bool(item, "only_chat"));
                    hideMyDynamicSwitch.setChecked(bool(item, "hide_my_notes"));
                    hideTheirDynamicSwitch.setChecked(bool(item, "hide_their_notes"));
                    String title = displayName(item);
                    if (!title.isEmpty()) profileTitle.setText(title);
                    String image = ImageLoader.get().absoluteUrl(this, Jsons.string(item, "avatar"));
                    ImageLoader.get().load(image, avatar, R.drawable.ic_person);
                    refreshGeneratedClue();
                    break;
                }
            }
            loadFinished();
        });
    }

    private void chooseRecommendationRecipients() {
        if (!isPrivate() || peerId() <= 0L || actionRequest != null) return;
        recommendationRecipientPicker.launch(SocialDirectoryActivity.pickFriendsIntent(
            this,
            10,
            translated(R.string.choose_recommendation_recipients),
            new long[]{AppAccess.from(this).session().actorId(), peerId()},
            "不能推荐给自己或当前联系人"
        ));
    }

    private void sendRecommendedContact(List<JsonObject> recipients, int index, int successCount, int failedCount) {
        if (isFinishing() || isDestroyed()) return;
        if (index >= recipients.size()) {
            actionRequest = null;
            progress.setVisibility(View.INVISIBLE);
            if (failedCount == 0) {
                showMessage(getString(R.string.recommend_contact_sent, successCount));
            } else {
                showMessage(getString(R.string.recommend_contact_partial, successCount, failedCount));
            }
            return;
        }
        long recipientId = Jsons.longValue(recipients.get(index), "user_id");
        if (recipientId <= 0L) {
            sendRecommendedContact(recipients, index + 1, successCount, failedCount + 1);
            return;
        }
        progress.setVisibility(View.VISIBLE);
        JsonObject metadata = recommendedContactMetadata();
        JsonObject attachment = new JsonObject();
        attachment.addProperty("media_type", "contact_card");
        attachment.addProperty("url", "/api/user/profiles/" + peerId());
        attachment.addProperty("file_name", ContactCardIdentity.displayName(metadata));
        attachment.add("metadata", metadata);
        JsonArray attachments = new JsonArray();
        attachments.add(attachment);
        JsonArray tags = new JsonArray();
        tags.add("名片");
        JsonObject body = new JsonObject();
        body.addProperty("to_user_id", recipientId);
        body.addProperty("content", "");
        body.add("attachments", attachments);
        body.add("tags", tags);
        actionRequest = AppAccess.from(this).repository().post("/api/user/messages/private", body, result -> {
            actionRequest = null;
            if (isFinishing() || isDestroyed()) return;
            sendRecommendedContact(
                recipients,
                index + 1,
                successCount + (result.isSuccessful() ? 1 : 0),
                failedCount + (result.isSuccessful() ? 0 : 1)
            );
        });
    }

    private JsonObject recommendedContactMetadata() {
        JsonObject metadata = ContactCardIdentity.metadata(friendProfile, peerId(), "", false);
        metadata.addProperty("recommended_by_user_id", AppAccess.from(this).session().actorId());
        return metadata;
    }

    private void loadBlacklist() {
        blacklistRequest = AppAccess.from(this).repository().get("/api/user/blacklist", new LinkedHashMap<>(), result -> {
            blacklistRequest = null;
            if (isFinishing() || isDestroyed()) return;
            boolean found = false;
            if (result.isSuccessful()) {
                for (JsonObject item : items(result.dataObject())) {
                    long blockedId = Jsons.longValue(item, "blocked_user_id");
                    if (blockedId == 0L) blockedId = Jsons.longValue(item, "user_id");
                    if (blockedId == peerId()) { found = true; break; }
                }
            }
            originalBlacklisted = found;
            blacklistSwitch.setChecked(found);
            loadFinished();
        });
    }

    private void saveConversationPreference(String field, boolean value) {
        if (effectiveTargetId <= 0L || actionRequest != null) {
            if (effectiveTargetId <= 0L) showMessage(getString(R.string.permission_save_failed));
            return;
        }
        JsonObject body = new JsonObject();
        body.addProperty(field, value);
        progress.setVisibility(View.VISIBLE);
        String path = "/api/user/message-center/" + preferenceType() + "/" + effectiveTargetId + "/preference";
        actionRequest = AppAccess.from(this).repository().put(path, body, result -> {
            actionRequest = null;
            if (isFinishing() || isDestroyed()) return;
            progress.setVisibility(View.INVISIBLE);
            if (!result.isSuccessful()) {
                showMessage(result.message().isEmpty() ? getString(R.string.permission_save_failed) : result.message());
                loadMessageCenter();
            }
        });
    }

    private void saveFriendPermissions() {
        if (!isPrivate() || peerId() <= 0L || actionRequest != null) return;
        JsonObject body = new JsonObject();
        body.addProperty("remark", text(remarkInput));
        body.addProperty("relationship_label", text(relationshipInput));
        body.addProperty("special_care", specialCareSwitch.isChecked());
        body.addProperty("only_chat", onlyChatSwitch.isChecked());
        body.addProperty("hide_my_notes", hideMyDynamicSwitch.isChecked());
        body.addProperty("hide_their_notes", hideTheirDynamicSwitch.isChecked());
        progress.setVisibility(View.VISIBLE);
        actionRequest = AppAccess.from(this).repository().put("/api/user/friends/" + peerId(), body, result -> {
            actionRequest = null;
            if (isFinishing() || isDestroyed()) return;
            if (!result.isSuccessful()) {
                progress.setVisibility(View.INVISIBLE);
                showMessage(result.message().isEmpty() ? getString(R.string.permission_save_failed) : result.message());
                return;
            }
            if (blacklistSwitch.isChecked() != originalBlacklisted) toggleBlacklist();
            else {
                progress.setVisibility(View.INVISIBLE);
                showMessage(getString(R.string.permission_saved));
            }
        });
    }

    private void toggleBlacklist() {
        actionRequest = AppAccess.from(this).repository().post("/api/user/blacklist/" + peerId(), new JsonObject(), result -> {
            actionRequest = null;
            if (isFinishing() || isDestroyed()) return;
            progress.setVisibility(View.INVISIBLE);
            if (result.isSuccessful()) {
                originalBlacklisted = blacklistSwitch.isChecked();
                showMessage(getString(R.string.permission_saved));
            } else {
                blacklistSwitch.setChecked(originalBlacklisted);
                showMessage(result.message().isEmpty() ? getString(R.string.permission_save_failed) : result.message());
            }
        });
    }

    private void setBackground(ActivityResult result) {
        if (result.getResultCode() != RESULT_OK || result.getData() == null) return;
        ArrayList<Uri> uris = result.getData()
            .getParcelableArrayListExtra(MediaPickerActivity.EXTRA_SELECTED_URIS);
        Uri uri = uris == null || uris.isEmpty() ? null : uris.get(0);
        if (uri == null) return;
        try {
            getContentResolver().takePersistableUriPermission(uri, Intent.FLAG_GRANT_READ_URI_PERMISSION);
        } catch (SecurityException ignored) { }
        ChatBackgroundStore.setConversation(this, backgroundIdentity(), uri.toString());
        renderBackgroundState();
        showMessage(translated(R.string.background_updated));
    }

    private void renderBackgroundState() {
        if (ChatBackgroundStore.usesSystemDefault(this, backgroundIdentity())) {
            backgroundState.setText(R.string.background_uses_system_default);
        } else if (ChatBackgroundStore.hasConversation(this, backgroundIdentity())) {
            backgroundState.setText(R.string.background_customized);
        } else {
            backgroundState.setText(R.string.background_follows_global);
        }
    }

    private void openProfile() {
        if (isPrivate()) {
            if (getIntent().getBooleanExtra(EXTRA_FROM_PROFILE, false)) finish();
            else UserProfileActivity.openFromPermission(this, peerId());
            return;
        }
        GroupSpaceActivity.open(this, effectiveTargetId, clean(getIntent().getStringExtra(EXTRA_TITLE)));
    }

    private void generateRelationshipLabel() {
        String value;
        if (specialCareSwitch.isChecked()) value = translated(R.string.special_care);
        else if (!friendGroupName.isEmpty()) value = friendGroupName;
        else if (onlyChatSwitch.isChecked()) value = translated(R.string.only_chat);
        else value = translated(R.string.relationship_default_friend);
        relationshipInput.setText(value);
        relationshipInput.setSelection(value.length());
        showMessage(translated(R.string.relationship_label_generated));
    }

    private void refreshGeneratedClue() {
        if (clueInput == null) return;
        ArrayList<String> clues = new ArrayList<>();
        if (!friendCreatedAt.isEmpty()) {
            String date = friendCreatedAt.length() > 10 ? friendCreatedAt.substring(0, 10) : friendCreatedAt;
            clues.add(translated(R.string.clue_friend_since) + ": " + date);
        }
        if (!friendGroupName.isEmpty()) {
            clues.add(translated(R.string.clue_friend_group) + ": " + friendGroupName);
        }
        if (specialCareSwitch != null && specialCareSwitch.isChecked()) {
            clues.add(translated(R.string.clue_special_care));
        }
        if (onlyChatSwitch != null && onlyChatSwitch.isChecked()) {
            clues.add(translated(R.string.clue_only_chat));
        }
        if (clues.isEmpty()) clues.add(translated(R.string.clue_no_details));
        clueInput.setText(android.text.TextUtils.join(" · ", clues));
    }

    private String translated(int stringId) {
        CharSequence value = RuntimeLanguage.translate(this, getString(stringId));
        return value == null ? getString(stringId) : value.toString();
    }

    private boolean matchesConversation(JsonObject item) {
        if (!preferenceType().equals(Jsons.string(item, "type"))) return false;
        if (effectiveTargetId > 0L && Jsons.longValue(item, "target_id") == effectiveTargetId) return true;
        return isPrivate() && peerId() > 0L && Jsons.longValue(item, "peer_user_id") == peerId();
    }

    private String displayName(JsonObject item) {
        for (String key : new String[]{"remark", "title", "nickname", "name", "account", "uid"}) {
            String value = clean(Jsons.string(item, key));
            if (!value.isEmpty() && !"null".equalsIgnoreCase(value)) return value;
        }
        return "";
    }

    private String first(JsonObject item, String... keys) {
        for (String key : keys) {
            String value = clean(Jsons.string(item, key));
            if (!value.isEmpty() && !"null".equalsIgnoreCase(value)) return value;
        }
        return "";
    }

    private void setCheckedSilently(MaterialSwitch view, boolean checked) {
        loadingControls = true;
        view.setChecked(checked);
        loadingControls = false;
    }

    private void loadFinished() {
        pendingLoads = Math.max(0, pendingLoads - 1);
        if (pendingLoads == 0 && actionRequest == null) progress.setVisibility(View.INVISIBLE);
    }

    private void showMessage(String message) {
        Snackbar.make(findViewById(android.R.id.content), message, Snackbar.LENGTH_LONG).show();
    }

    private static JsonObject[] items(JsonObject data) {
        if (data == null || !data.has("items") || !data.get("items").isJsonArray()) return new JsonObject[0];
        JsonArray array = data.getAsJsonArray("items");
        JsonObject[] values = new JsonObject[array.size()];
        int count = 0;
        for (JsonElement element : array) if (element.isJsonObject()) values[count++] = element.getAsJsonObject();
        if (count == values.length) return values;
        JsonObject[] trimmed = new JsonObject[count];
        System.arraycopy(values, 0, trimmed, 0, count);
        return trimmed;
    }

    private static boolean bool(JsonObject object, String key) {
        try { return object.has(key) && !object.get(key).isJsonNull() && object.get(key).getAsBoolean(); }
        catch (RuntimeException ignored) { return false; }
    }

    private static String text(TextInputEditText input) {
        return input.getText() == null ? "" : input.getText().toString().trim();
    }

    private static String clean(String value) { return value == null ? "" : value.trim(); }
    private String type() { return clean(getIntent().getStringExtra(EXTRA_TYPE)); }
    private String preferenceType() { return isChatRoom() ? TYPE_GROUP : type(); }
    private boolean isPrivate() { return TYPE_PRIVATE.equals(type()); }
    private boolean isChatRoom() { return TYPE_CHAT_ROOM.equals(type()); }
    private long peerId() { return getIntent().getLongExtra(EXTRA_PEER_ID, 0L); }
    private String backgroundIdentity() { return clean(getIntent().getStringExtra(EXTRA_BACKGROUND_IDENTITY)); }

    @Override protected void onDestroy() {
        if (centerRequest != null) centerRequest.cancel();
        if (friendRequest != null) friendRequest.cancel();
        if (blacklistRequest != null) blacklistRequest.cancel();
        if (actionRequest != null) actionRequest.cancel();
        super.onDestroy();
    }
}
