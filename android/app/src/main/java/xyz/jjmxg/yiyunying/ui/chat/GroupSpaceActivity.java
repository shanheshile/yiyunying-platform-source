package xyz.jjmxg.yiyunying.ui.chat;

import android.content.ClipData;
import android.content.ClipboardManager;
import android.content.Context;
import android.content.Intent;
import android.database.Cursor;
import android.graphics.Bitmap;
import android.net.Uri;
import android.os.Bundle;
import android.provider.OpenableColumns;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.widget.CheckBox;
import android.widget.CompoundButton;
import android.widget.EditText;
import android.widget.LinearLayout;
import android.widget.RadioButton;
import android.widget.ScrollView;
import android.widget.TextView;
import android.widget.ImageView;

import androidx.activity.result.ActivityResultLauncher;
import androidx.activity.result.contract.ActivityResultContracts;
import androidx.activity.OnBackPressedCallback;
import androidx.annotation.NonNull;
import androidx.appcompat.app.AlertDialog;
import androidx.appcompat.app.AppCompatActivity;
import androidx.recyclerview.widget.DiffUtil;
import androidx.recyclerview.widget.LinearLayoutManager;
import androidx.recyclerview.widget.RecyclerView;

import xyz.jjmxg.yiyunying.ui.common.YiyunyingDialogBuilder;
import com.bumptech.glide.Glide;
import com.google.android.material.button.MaterialButton;
import com.google.android.material.card.MaterialCardView;
import com.google.android.material.checkbox.MaterialCheckBox;
import com.google.android.material.materialswitch.MaterialSwitch;
import com.google.android.material.snackbar.Snackbar;
import com.google.gson.JsonArray;
import com.google.gson.JsonElement;
import com.google.gson.JsonNull;
import com.google.gson.JsonObject;
import com.google.gson.JsonParser;
import com.google.zxing.BarcodeFormat;
import com.journeyapps.barcodescanner.BarcodeEncoder;

import java.util.ArrayList;
import java.util.ArrayDeque;
import java.text.SimpleDateFormat;
import java.util.Calendar;
import java.util.Date;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Locale;
import java.util.Map;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.core.AppAccess;

import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.data.api.RequestHandle;
import xyz.jjmxg.yiyunying.databinding.ActivityGroupSpaceBinding;
import xyz.jjmxg.yiyunying.databinding.ItemRecordBinding;
import xyz.jjmxg.yiyunying.ui.common.ImageLoader;
import xyz.jjmxg.yiyunying.ui.common.QrShareDialog;
import xyz.jjmxg.yiyunying.ui.profile.UserProfileActivity;
import xyz.jjmxg.yiyunying.ui.social.SocialDirectoryActivity;
import xyz.jjmxg.yiyunying.ui.upload.ContentUriRequestBody;
import xyz.jjmxg.yiyunying.ui.upload.FilePickerActivity;
import xyz.jjmxg.yiyunying.ui.upload.FilePreviewActivity;
import xyz.jjmxg.yiyunying.ui.upload.ImageGalleryActivity;
import xyz.jjmxg.yiyunying.ui.upload.MediaPickerActivity;
import xyz.jjmxg.yiyunying.ui.upload.UploadPolicyStore;

public final class GroupSpaceActivity extends xyz.jjmxg.yiyunying.ui.common.SystemInsetActivity {
    private static final String EXTRA_ROOM_ID = "room_id";
    private static final String EXTRA_TITLE = "title";
    private static final String EXTRA_SECTION = "section";
    private ActivityGroupSpaceBinding binding;
    private final List<JsonObject> items = new ArrayList<>();
    private SpaceAdapter adapter;
    private RequestHandle request;
    private RequestHandle roomRequest;
    private RequestHandle actionRequest;
    private RequestHandle uploadRequest;
    private RequestHandle voteOptionUploadRequest;
    private RequestHandle avatarUploadRequest;
    private RequestHandle avatarPolicyRequest;
    private long roomId;
    private String roomKind = "group";
    private String section = "members";
    private String uploadMode = "file";
    private long targetAlbumId;
    private long currentFolderId;
    private String currentFolderName = "文件";
    private String groupNumber = "";
    private String groupCreatedAt = "";
    private String currentRole = "member";
    private String roomIcon = "";
    private String roomName = "";
    private String roomDescription = "";
    private String roomAnnouncement = "";
    private boolean roomProfilesEnabled = true;
    private boolean groupAvatarUploadEnabled = true;
    private boolean chatroomAvatarUploadEnabled = true;
    private final ArrayDeque<JsonObject> invitationQueue = new ArrayDeque<>();
    private int invitationTotal;
    private int invitationSucceeded;
    private int invitationFailed;
    private boolean invitationShareHistory = true;
    private String invitationMessage = "";
    private final ArrayDeque<Long> folderParents = new ArrayDeque<>();
    private final ArrayDeque<String> folderParentNames = new ArrayDeque<>();
    private final ArrayDeque<Uri> uploadQueue = new ArrayDeque<>();
    private int uploadTotal;
    private int uploadCompleted;
    private VoteOptionDraft pendingVoteOptionImage;
    private final ActivityResultLauncher<Intent> avatarPicker = registerForActivityResult(
        new ActivityResultContracts.StartActivityForResult(), result -> {
            if (result.getResultCode() != RESULT_OK || result.getData() == null) return;
            ArrayList<Uri> selected = result.getData()
                .getParcelableArrayListExtra(MediaPickerActivity.EXTRA_SELECTED_URIS);
            if (selected == null || selected.isEmpty()) return;
            uploadRoomAvatar(selected.get(0));
        });
    private final ActivityResultLauncher<Intent> mediaPicker = registerForActivityResult(
        new ActivityResultContracts.StartActivityForResult(), result -> {
            if (result.getResultCode() != RESULT_OK || result.getData() == null) return;
            ArrayList<Uri> selected = result.getData()
                .getParcelableArrayListExtra(MediaPickerActivity.EXTRA_SELECTED_URIS);
            selectedFiles(selected);
        });
    private final ActivityResultLauncher<Intent> filePicker = registerForActivityResult(
        new ActivityResultContracts.StartActivityForResult(), result -> {
            if (result.getResultCode() != RESULT_OK || result.getData() == null) return;
            ArrayList<String> values = result.getData()
                .getStringArrayListExtra(FilePickerActivity.EXTRA_SELECTED_URIS);
            if (values == null || values.isEmpty()) return;
            ArrayList<Uri> selected = new ArrayList<>();
            for (String value : values) if (value != null && !value.isEmpty()) selected.add(Uri.parse(value));
            selectedFiles(selected);
        });
    private final ActivityResultLauncher<Intent> voteOptionImagePicker = registerForActivityResult(
        new ActivityResultContracts.StartActivityForResult(), result -> {
            VoteOptionDraft draft = pendingVoteOptionImage;
            pendingVoteOptionImage = null;
            if (draft == null || draft.root.getParent() == null
                || result.getResultCode() != RESULT_OK || result.getData() == null) return;
            ArrayList<Uri> selected = result.getData()
                .getParcelableArrayListExtra(MediaPickerActivity.EXTRA_SELECTED_URIS);
            if (selected == null || selected.isEmpty()) return;
            draft.imageUri = selected.get(0);
            draft.imageUrl = "";
            draft.imageButton.setText("更换选项图片");
            draft.imagePreview.setVisibility(View.VISIBLE);
            Glide.with(this).load(draft.imageUri).centerCrop().into(draft.imagePreview);
        });
    private final ActivityResultLauncher<Intent> friendPicker = registerForActivityResult(
        new ActivityResultContracts.StartActivityForResult(), result -> {
            if (result.getResultCode() != RESULT_OK || result.getData() == null) return;
            String serialized = result.getData().getStringExtra(SocialDirectoryActivity.EXTRA_SELECTED_ITEMS);
            if (serialized == null || serialized.isEmpty()) return;
            try {
                JsonArray values = JsonParser.parseString(serialized).getAsJsonArray();
                List<JsonObject> selected = new ArrayList<>();
                for (JsonElement value : values) if (value.isJsonObject()) selected.add(value.getAsJsonObject().deepCopy());
                if (!selected.isEmpty()) confirmInvitations(selected);
            } catch (RuntimeException exception) {
                toast("好友选择结果无法读取，请重新选择");
            }
        });

    public static void open(Context context, long roomId, String title) {
        open(context, roomId, title, "members");
    }

    public static void open(Context context, long roomId, String title, String section) {
        context.startActivity(new Intent(context, GroupSpaceActivity.class)
            .putExtra(EXTRA_ROOM_ID, roomId)
            .putExtra(EXTRA_TITLE, title)
            .putExtra(EXTRA_SECTION, section));
    }

    @Override protected void onCreate(Bundle state) {
        super.onCreate(state);
        roomId = getIntent().getLongExtra(EXTRA_ROOM_ID, 0);
        if (roomId <= 0) { finish(); return; }
        section = normalizeSection(getIntent().getStringExtra(EXTRA_SECTION));
        binding = ActivityGroupSpaceBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());
        binding.toolbar.setNavigationOnClickListener(view -> navigateBack());
        getOnBackPressedDispatcher().addCallback(this, new OnBackPressedCallback(true) {
            @Override public void handleOnBackPressed() { navigateBack(); }
        });
        binding.groupName.setText(getIntent().getStringExtra(EXTRA_TITLE));
        binding.groupAvatar.setImageResource(R.drawable.ic_group);
        binding.groupAvatarButton.setOnClickListener(view ->
            avatarPicker.launch(MediaPickerActivity.imageIntent(this, 1)));
        binding.groupEditButton.setOnClickListener(view -> showRoomProfileEditor());
        binding.groupQrButton.setOnClickListener(view -> showGroupQr());
        adapter = new SpaceAdapter();
        binding.recycler.setLayoutManager(new LinearLayoutManager(this));
        binding.recycler.setHasFixedSize(true);
        binding.recycler.setItemViewCacheSize(12);
        binding.recycler.setAdapter(adapter);
        binding.swipeRefresh.setOnRefreshListener(this::load);
        int initialTab = "files".equals(section) ? R.id.tabFiles
            : ("albums".equals(section) ? R.id.tabAlbums
            : ("votes".equals(section) ? R.id.tabVotes
            : ("solitaires".equals(section) ? R.id.tabSolitaires : R.id.tabMembers)));
        binding.tabs.check(initialTab);
        binding.tabs.setOnCheckedStateChangeListener((group, checkedIds) -> {
            if (checkedIds.isEmpty()) return;
            int id = checkedIds.get(0);
            section = id == R.id.tabFiles ? "files" : (id == R.id.tabAlbums ? "albums"
                : (id == R.id.tabVotes ? "votes" : (id == R.id.tabSolitaires ? "solitaires" : "members")));
            currentFolderId = 0;
            currentFolderName = fileRootName();
            folderParents.clear();
            folderParentNames.clear();
            updateActionLabel();
            updateFolderNavigation();
            load();
        });
        binding.actionButton.setOnClickListener(view -> createForSection());
        updateActionLabel();
        loadAvatarPolicy();
        loadRoom();
        load();
    }

    private static String normalizeSection(String value) {
        if ("files".equals(value) || "albums".equals(value) || "votes".equals(value)
            || "solitaires".equals(value)) return value;
        return "members";
    }

    private void loadRoom() {
        if (roomRequest != null) roomRequest.cancel();
        roomRequest = AppAccess.from(this).repository().get(base(), new LinkedHashMap<>(), result -> {
            roomRequest = null;
            if (binding == null || isFinishing() || isDestroyed() || !result.isSuccessful()) return;
            JsonObject room = Jsons.object(result.dataObject(), "room");
            roomKind = "chat_room".equals(Jsons.string(room, "room_kind")) ? "chat_room" : "group";
            binding.toolbar.setTitle(entityLabel() + "资料");
            binding.groupQrButton.setText(entityLabel() + "二维码");
            binding.tabMembers.setText(memberLabel());
            binding.tabFiles.setText(fileRootName());
            binding.tabAlbums.setText(spacePrefix() + "相册");
            binding.tabVotes.setText(spacePrefix() + "投票");
            binding.tabSolitaires.setText(spacePrefix() + "接龙");
            String roomName = Jsons.string(room, "name");
            this.roomName = roomName;
            roomDescription = Jsons.string(room, "description");
            roomAnnouncement = Jsons.string(room, "announcement");
            if (!roomName.isEmpty()) binding.groupName.setText(roomName);
            currentRole = Jsons.string(room, "current_role");
            if (currentRole.isEmpty()) currentRole = "member";
            String nextIcon = Jsons.string(room, "icon");
            ImageLoader.get().invalidate(ImageLoader.get().absoluteUrl(this, roomIcon));
            roomIcon = nextIcon;
            ImageLoader.get().load(
                ImageLoader.get().absoluteUrl(this, roomIcon),
                binding.groupAvatar,
                R.drawable.ic_group
            );
            groupNumber = String.valueOf(20000000000L + Jsons.longValue(room, "id"));
            groupCreatedAt = Jsons.string(room, "created_at");
            String created = groupCreatedAt.isEmpty() ? "未记录" : groupCreatedAt;
            binding.groupMeta.setText(entityNumberLabel() + " " + groupNumber
                + " · " + memberLabel() + " " + Jsons.longValue(room, "member_count") + " 人"
                + " · 创建时间 " + created);
            binding.announcement.setText(roomAnnouncement.isEmpty()
                ? "暂无" + entityLabel() + "公告"
                : entityLabel() + "公告：" + roomAnnouncement);
            if (currentFolderId == 0) currentFolderName = fileRootName();
            renderAvatarAction();
            updateActionLabel();
            updateFolderNavigation();
            if (adapter != null) adapter.notifyDataSetChanged();
        });
    }

    private void loadAvatarPolicy() {
        if (avatarPolicyRequest != null) avatarPolicyRequest.cancel();
        avatarPolicyRequest = AppAccess.from(this).repository().getPublic(
            "/api/public/bootstrap", new LinkedHashMap<>(), result -> {
                avatarPolicyRequest = null;
                if (binding == null || isFinishing() || isDestroyed() || !result.isSuccessful()) return;
                UploadPolicyStore.update(this, Jsons.object(result.dataObject(), "upload_limits"));
                JsonObject features = Jsons.object(result.dataObject(), "features");
                roomProfilesEnabled = featureEnabled(features, "chat_rooms", true);
                groupAvatarUploadEnabled = featureEnabled(features, "group_avatar_upload", true);
                chatroomAvatarUploadEnabled = featureEnabled(features, "chatroom_avatar_upload", true);
                renderAvatarAction();
            });
    }

    private void renderAvatarAction() {
        if (binding == null) return;
        boolean manager = "owner".equals(currentRole) || "admin".equals(currentRole);
        boolean enabled = isChatRoom() ? chatroomAvatarUploadEnabled : groupAvatarUploadEnabled;
        binding.groupAvatarButton.setText("更换" + entityLabel() + "头像");
        binding.groupAvatarButton.setVisibility(manager && roomProfilesEnabled && enabled ? View.VISIBLE : View.GONE);
        binding.groupEditButton.setText("编辑" + entityLabel() + "资料");
        binding.groupEditButton.setVisibility(manager && roomProfilesEnabled ? View.VISIBLE : View.GONE);
        binding.groupAvatar.setContentDescription(entityLabel() + "头像，点击可预览");
        binding.groupAvatar.setOnClickListener(view -> {
            if (roomIcon.isEmpty()) {
                if (manager && roomProfilesEnabled && enabled) {
                    avatarPicker.launch(MediaPickerActivity.imageIntent(this, 1));
                }
                return;
            }
            JsonObject image = new JsonObject();
            image.addProperty("url", roomIcon);
            image.addProperty("file_name", entityLabel() + "头像");
            ImageGalleryActivity.open(this, java.util.Collections.singletonList(image), 0);
        });
    }

    private void showRoomProfileEditor() {
        if (binding == null || actionRequest != null) return;
        LinearLayout content = form();
        EditText name = input(entityLabel() + "名称");
        name.setText(roomName);
        name.setSingleLine(true);
        EditText description = input(entityLabel() + "简介");
        description.setText(roomDescription);
        description.setMinLines(2);
        description.setMaxLines(5);
        EditText announcement = input(entityLabel() + "公告");
        announcement.setText(roomAnnouncement);
        announcement.setMinLines(2);
        announcement.setMaxLines(6);
        content.addView(name);
        content.addView(description);
        content.addView(announcement);
        AlertDialog dialog = new YiyunyingDialogBuilder(this)
            .setTitle("编辑" + entityLabel() + "资料")
            .setView(content)
            .setPositiveButton("保存", null)
            .setNegativeButton("取消", null)
            .create();
        dialog.setOnShowListener(ignored -> dialog.getButton(AlertDialog.BUTTON_POSITIVE)
            .setOnClickListener(view -> {
                String nextName = name.getText().toString().trim();
                if (nextName.isEmpty()) {
                    name.setError(entityLabel() + "名称不能为空");
                    return;
                }
                JsonObject body = new JsonObject();
                body.addProperty("name", nextName);
                body.addProperty("description", description.getText().toString().trim());
                body.addProperty("announcement", announcement.getText().toString().trim());
                dialog.dismiss();
                binding.progress.setVisibility(View.VISIBLE);
                actionRequest = AppAccess.from(this).repository().put(base(), body, result -> {
                    actionRequest = null;
                    if (binding == null || isFinishing() || isDestroyed()) return;
                    binding.progress.setVisibility(View.INVISIBLE);
                    if (!result.isSuccessful()) {
                        toast(result.message().isEmpty() ? entityLabel() + "资料保存失败" : result.message());
                        return;
                    }
                    toast(result.message().isEmpty() ? entityLabel() + "资料已保存" : result.message());
                    loadRoom();
                });
            }));
        dialog.show();
    }

    private void uploadRoomAvatar(Uri uri) {
        if (uri == null || avatarUploadRequest != null || binding == null) return;
        UriFile file = uriFile(uri);
        if (!UploadPolicyStore.accepts(this, "image", file.size)) {
            toast(UploadPolicyStore.rejectionMessage(this, "image", file.size));
            return;
        }
        binding.progress.setVisibility(View.VISIBLE);
        binding.groupAvatarButton.setEnabled(false);
        avatarUploadRequest = AppAccess.from(this).repository().upload(
            base() + "/avatar",
            file.name,
            file.mime,
            new ContentUriRequestBody(getContentResolver(), uri, file.mime, file.size),
            new LinkedHashMap<>(),
            result -> {
                avatarUploadRequest = null;
                if (binding == null || isFinishing() || isDestroyed()) return;
                binding.progress.setVisibility(View.INVISIBLE);
                binding.groupAvatarButton.setEnabled(true);
                if (!result.isSuccessful()) {
                    toast(result.message().isEmpty() ? entityLabel() + "头像上传失败" : result.message());
                    return;
                }
                ImageLoader.get().invalidate(ImageLoader.get().absoluteUrl(this, roomIcon));
                roomIcon = Jsons.string(result.dataObject(), "icon");
                if (roomIcon.isEmpty()) roomIcon = Jsons.string(result.dataObject(), "avatar");
                ImageLoader.get().load(
                    ImageLoader.get().absoluteUrl(this, roomIcon),
                    binding.groupAvatar,
                    R.drawable.ic_group
                );
                toast(result.message().isEmpty() ? entityLabel() + "头像已更新" : result.message());
                loadRoom();
            }
        );
    }

    private static boolean featureEnabled(JsonObject features, String key, boolean fallback) {
        if (features == null || !features.has(key) || features.get(key).isJsonNull()) return fallback;
        try {
            JsonElement value = features.get(key);
            return value.isJsonObject()
                ? value.getAsJsonObject().get("enabled").getAsBoolean()
                : value.getAsBoolean();
        } catch (RuntimeException ignored) {
            return fallback;
        }
    }

    private void updateActionLabel() {
        if (binding == null) return;
        if ("members".equals(section)) { binding.actionButton.setText("邀请成员"); binding.actionButton.setVisibility(View.VISIBLE); }
        else if ("files".equals(section)) { binding.actionButton.setText("上传 / 新建文件夹"); binding.actionButton.setVisibility(View.VISIBLE); }
        else if ("albums".equals(section)) { binding.actionButton.setText("上传 / 新建相册"); binding.actionButton.setVisibility(View.VISIBLE); }
        else if ("votes".equals(section)) { binding.actionButton.setText("发起投票"); binding.actionButton.setVisibility(View.VISIBLE); }
        else { binding.actionButton.setText("发起接龙"); binding.actionButton.setVisibility(View.VISIBLE); }
    }

    private void load() {
        if (request != null) request.cancel();
        binding.progress.setVisibility(View.VISIBLE);
        LinkedHashMap<String, String> query = new LinkedHashMap<>(); query.put("limit", "200");
        if ("files".equals(section)) query.put("parent_id", String.valueOf(currentFolderId));
        request = AppAccess.from(this).repository().get(base() + "/" + section, query, result -> {
            request = null;
            if (binding == null) return;
            binding.progress.setVisibility(View.INVISIBLE); binding.swipeRefresh.setRefreshing(false);
            if (!result.isSuccessful()) { Snackbar.make(binding.getRoot(), result.message().isEmpty() ? entityLabel() + "空间加载失败" : result.message(), Snackbar.LENGTH_LONG).show(); return; }
            adapter.submit(section, result.objectItems());
            binding.emptyText.setVisibility(items.isEmpty() ? View.VISIBLE : View.GONE);
            binding.emptyText.setText("members".equals(section) ? memberLabel() + "为空"
                : ("files".equals(section) ? "当前文件夹为空，可上传文件或新建文件夹" : "这里还没有内容，可点击右下角创建"));
        });
    }

    private void createForSection() {
        if ("members".equals(section)) { inviteMember(); return; }
        if ("files".equals(section)) { fileActions(); return; }
        if ("albums".equals(section)) { albumActions(); return; }
        if ("votes".equals(section)) { voteForm(); return; }
        solitaireForm();
    }

    private void fileActions() {
        new YiyunyingDialogBuilder(this)
            .setTitle(currentFolderId == 0 ? fileRootName() : currentFolderName)
            .setItems(new String[]{"上传文件", "新建文件夹"}, (dialog, which) -> {
                if (which == 0) {
                    uploadMode = "file";
                    filePicker.launch(FilePickerActivity.pickerIntent(this, 50));
                } else {
                    EditText name = input("文件夹名称");
                    AlertDialog create = new YiyunyingDialogBuilder(this)
                        .setTitle("新建" + fileRootName() + "夹")
                        .setView(name)
                        .setPositiveButton("创建", null)
                        .setNegativeButton("取消", null)
                        .create();
                    create.setOnShowListener(ignored -> create.getButton(AlertDialog.BUTTON_POSITIVE).setOnClickListener(view -> {
                        String value = name.getText().toString().trim();
                        if (value.isEmpty()) { toast("请输入文件夹名称"); return; }
                        JsonObject body = new JsonObject();
                        body.addProperty("name", value);
                        body.addProperty("is_folder", true);
                        body.addProperty("parent_id", currentFolderId);
                        create.dismiss();
                        post(base() + "/files", body);
                    }));
                    create.show();
                }
            })
            .setNegativeButton("取消", null)
            .show();
    }

    private void inviteMember() {
        long[] excluded = new long[items.size()];
        int count = 0;
        for (JsonObject member : items) {
            long userId = Jsons.longValue(member, "user_id");
            if (userId > 0) excluded[count++] = userId;
        }
        if (count != excluded.length) excluded = java.util.Arrays.copyOf(excluded, count);
        friendPicker.launch(SocialDirectoryActivity.pickFriendsIntent(this, 50, "邀请" + memberLabel(), excluded));
    }

    private void confirmInvitations(List<JsonObject> selected) {
        LinearLayout content = form();
        TextView summary = new TextView(this);
        summary.setText("已选择 " + selected.size() + " 位好友\n" + selectedNames(selected));
        summary.setTextColor(getColor(R.color.on_surface_variant));
        summary.setPadding(dp(8), dp(4), dp(8), dp(8));
        EditText message = input("邀请说明（选填）");
        CheckBox shareHistory = new CheckBox(this);
        shareHistory.setText("允许新成员查看此前聊天记录");
        shareHistory.setChecked(true);
        TextView historyHint = new TextView(this);
        historyHint.setText("关闭后，新成员只能看到加入" + entityLabel() + "之后产生的消息。");
        historyHint.setTextSize(13f);
        historyHint.setTextColor(getColor(R.color.on_surface_variant));
        historyHint.setPadding(dp(8), 0, dp(8), dp(4));
        content.addView(summary);
        content.addView(message);
        content.addView(shareHistory);
        content.addView(historyHint);
        new YiyunyingDialogBuilder(this)
            .setTitle("确认邀请成员")
            .setView(content)
            .setPositiveButton("发送邀请", (dialog, which) -> startInvitations(
                selected, message.getText().toString().trim(), shareHistory.isChecked()))
            .setNegativeButton("取消", null)
            .show();
    }

    private String selectedNames(List<JsonObject> selected) {
        StringBuilder names = new StringBuilder();
        for (JsonObject item : selected) {
            if (names.length() > 0) names.append("、");
            names.append(first(item, "remark", "nickname", "account"));
            if (names.length() > 80) { names.append("…"); break; }
        }
        return names.toString();
    }

    private void startInvitations(List<JsonObject> selected, String message, boolean shareHistory) {
        if (actionRequest != null) { toast("请等待当前操作完成"); return; }
        invitationQueue.clear();
        invitationQueue.addAll(selected);
        invitationTotal = selected.size();
        invitationSucceeded = 0;
        invitationFailed = 0;
        invitationMessage = message;
        invitationShareHistory = shareHistory;
        binding.progress.setVisibility(View.VISIBLE);
        sendNextInvitation();
    }

    private void sendNextInvitation() {
        JsonObject person = invitationQueue.pollFirst();
        if (person == null) {
            if (binding == null) return;
            binding.progress.setVisibility(View.INVISIBLE);
            String result = "邀请完成：成功 " + invitationSucceeded + " 人";
            if (invitationFailed > 0) result += "，失败 " + invitationFailed + " 人";
            toast(result);
            load();
            loadRoom();
            return;
        }
        JsonObject body = new JsonObject();
        String uid = Jsons.string(person, "uid");
        if (uid.isEmpty()) uid = Jsons.string(person, "public_no");
        if (uid.isEmpty()) uid = String.valueOf(Jsons.longValue(person, "user_id"));
        body.addProperty("user_uid", uid);
        body.addProperty("message", invitationMessage);
        body.addProperty("share_history", invitationShareHistory);
        actionRequest = AppAccess.from(this).repository().post(base() + "/invitations", body, result -> {
            actionRequest = null;
            if (binding == null) return;
            if (result.isSuccessful()) invitationSucceeded++; else invitationFailed++;
            sendNextInvitation();
        });
    }

    private void voteForm() {
        LinearLayout form = form();
        EditText title = input("投票标题");
        EditText description = input("投票说明（选填）");
        description.setMinLines(2);
        MaterialSwitch multiple = new MaterialSwitch(this);
        multiple.setText("允许多选");
        multiple.setMinHeight(dp(48));
        MaterialSwitch allowChange = new MaterialSwitch(this);
        allowChange.setText("投票后允许修改选择");
        allowChange.setChecked(true);
        allowChange.setMinHeight(dp(48));
        MaterialSwitch anonymous = new MaterialSwitch(this);
        anonymous.setText("匿名投票（" + memberLabel() + "不显示投票人）");
        anonymous.setMinHeight(dp(48));
        final long[] durationHours = {24L * 7L};
        MaterialButton duration = new MaterialButton(this);
        duration.setText("截止时间：7 天后");
        duration.setIconResource(R.drawable.ic_calendar);
        duration.setOnClickListener(view -> {
            String[] labels = {"1 小时后", "1 天后", "3 天后", "7 天后", "30 天后"};
            long[] hours = {1L, 24L, 72L, 168L, 720L};
            new YiyunyingDialogBuilder(this)
                .setTitle("选择投票截止时间")
                .setSingleChoiceItems(labels, selectedDurationIndex(hours, durationHours[0]), (choice, which) -> {
                    durationHours[0] = hours[which];
                    duration.setText("截止时间：" + labels[which]);
                    choice.dismiss();
                })
                .setNegativeButton("取消", null)
                .show();
        });
        TextView optionLabel = new TextView(this);
        optionLabel.setText("投票选项（可添加图片）");
        optionLabel.setTextAppearance(com.google.android.material.R.style.TextAppearance_Material3_TitleSmall);
        optionLabel.setPadding(0, dp(12), 0, dp(4));
        LinearLayout optionRows = new LinearLayout(this); optionRows.setOrientation(LinearLayout.VERTICAL);
        addVoteOption(optionRows, "选项 1");
        addVoteOption(optionRows, "选项 2");
        MaterialButton addOption = new MaterialButton(this); addOption.setText("添加选项");
        addOption.setIconResource(R.drawable.ic_add);
        addOption.setOnClickListener(view -> {
            if (optionRows.getChildCount() >= 20) { toast("移动端单次最多添加 20 个选项"); return; }
            addVoteOption(optionRows, "选项 " + (optionRows.getChildCount() + 1));
        });
        form.addView(title);
        form.addView(description);
        form.addView(multiple);
        form.addView(allowChange);
        form.addView(anonymous);
        form.addView(duration);
        form.addView(optionLabel); form.addView(optionRows); form.addView(addOption);
        ScrollView scroll = new ScrollView(this); scroll.addView(form);
        AlertDialog dialog = new YiyunyingDialogBuilder(this).setTitle("发起投票").setView(scroll)
            .setPositiveButton("发布", null)
            .setNegativeButton("取消", null).create();
        dialog.setOnShowListener(ignored -> dialog.getButton(AlertDialog.BUTTON_POSITIVE).setOnClickListener(view -> {
                String titleText = title.getText().toString().trim();
                List<VoteOptionDraft> values = collectVoteOptions(optionRows);
                if (titleText.isEmpty() || values.size() < 2) { toast("请填写标题和至少两个选项"); return; }
                JsonObject body = new JsonObject(); body.addProperty("title", titleText);
                body.addProperty("description", description.getText().toString().trim());
                body.addProperty("multiple_choice", multiple.isChecked());
                body.addProperty("multi_select", multiple.isChecked());
                body.addProperty("allow_multiple", multiple.isChecked());
                body.addProperty("min_select", 1);
                body.addProperty("max_select", multiple.isChecked() ? values.size() : 1);
                body.addProperty("allow_change", allowChange.isChecked());
                body.addProperty("anonymous", anonymous.isChecked());
                body.addProperty("ends_at", futureDateTime(durationHours[0]));
                dialog.getButton(AlertDialog.BUTTON_POSITIVE).setEnabled(false);
                dialog.getButton(AlertDialog.BUTTON_POSITIVE).setText("正在发布…");
                uploadVoteOptionImages(body, values, 0, dialog);
            }));
        dialog.show();
    }

    private void solitaireForm() {
        LinearLayout form = form();
        EditText title = input("接龙标题");
        EditText description = input("接龙说明（选填）");
        description.setMinLines(3);
        description.setGravity(android.view.Gravity.TOP | android.view.Gravity.START);
        final long[] durationHours = {24L * 7L};
        MaterialButton duration = new MaterialButton(this);
        duration.setText("截止时间：7 天后");
        duration.setIconResource(R.drawable.ic_calendar);
        duration.setOnClickListener(view -> {
            String[] labels = {"1 天后", "3 天后", "7 天后", "30 天后", "长期有效"};
            long[] hours = {24L, 72L, 168L, 720L, 0L};
            new YiyunyingDialogBuilder(this)
                .setTitle("选择接龙截止时间")
                .setSingleChoiceItems(labels, selectedDurationIndex(hours, durationHours[0]), (choice, which) -> {
                    durationHours[0] = hours[which];
                    duration.setText("截止时间：" + labels[which]);
                    choice.dismiss();
                })
                .setNegativeButton("取消", null)
                .show();
        });
        TextView hint = new TextView(this);
        hint.setText("参与者按提交成功的先后顺序自动编号；同一账号再次提交会更新自己的内容。");
        hint.setTextColor(getColor(R.color.on_surface_variant));
        hint.setTextSize(13f);
        hint.setPadding(dp(4), dp(8), dp(4), 0);
        form.addView(title);
        form.addView(description);
        form.addView(duration);
        form.addView(hint);
        AlertDialog dialog = new YiyunyingDialogBuilder(this)
            .setTitle("发起接龙")
            .setView(form)
            .setPositiveButton("发布接龙", null)
            .setNegativeButton("取消", null)
            .create();
        dialog.setOnShowListener(ignored -> dialog.getButton(AlertDialog.BUTTON_POSITIVE).setOnClickListener(view -> {
            String value = title.getText().toString().trim();
            if (value.isEmpty()) { toast("请输入接龙标题"); return; }
            JsonObject body = new JsonObject();
            body.addProperty("title", value);
            body.addProperty("description", description.getText().toString().trim());
            if (durationHours[0] > 0L) body.addProperty("ends_at", futureDateTime(durationHours[0]));
            dialog.dismiss();
            post(base() + "/solitaires", body);
        }));
        dialog.show();
    }

    private void addVoteOption(LinearLayout container, String hint) {
        LinearLayout row = new LinearLayout(this);
        row.setOrientation(LinearLayout.VERTICAL);
        row.setPadding(dp(6), dp(4), dp(6), dp(8));
        LinearLayout header = new LinearLayout(this);
        header.setOrientation(LinearLayout.HORIZONTAL);
        header.setGravity(android.view.Gravity.CENTER_VERTICAL);
        EditText option = input(hint); option.setSingleLine(true);
        header.addView(option, new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f));
        MaterialButton remove = new MaterialButton(this); remove.setIconResource(R.drawable.ic_close); remove.setContentDescription("删除这个投票选项");
        remove.setText(""); remove.setMinWidth(0); remove.setPadding(dp(10), 0, dp(10), 0);
        remove.setOnClickListener(view -> {
            if (container.getChildCount() <= 2) { toast("投票至少保留两个选项"); return; }
            container.removeView(row);
        });
        LinearLayout.LayoutParams removeParams = new LinearLayout.LayoutParams(dp(52), dp(48)); removeParams.setMarginStart(dp(6));
        header.addView(remove, removeParams);
        row.addView(header);
        LinearLayout media = new LinearLayout(this);
        media.setOrientation(LinearLayout.HORIZONTAL);
        media.setGravity(android.view.Gravity.CENTER_VERTICAL);
        MaterialButton imageButton = new MaterialButton(this);
        imageButton.setText("添加选项图片（可选）");
        imageButton.setIconResource(R.drawable.ic_album);
        ImageView imagePreview = new ImageView(this);
        imagePreview.setScaleType(ImageView.ScaleType.CENTER_CROP);
        imagePreview.setVisibility(View.GONE);
        media.addView(imageButton, new LinearLayout.LayoutParams(0, dp(48), 1f));
        LinearLayout.LayoutParams imageParams = new LinearLayout.LayoutParams(dp(64), dp(64));
        imageParams.setMarginStart(dp(10));
        media.addView(imagePreview, imageParams);
        row.addView(media);
        VoteOptionDraft draft = new VoteOptionDraft(row, option, imageButton, imagePreview);
        row.setTag(draft);
        imageButton.setOnClickListener(view -> {
            pendingVoteOptionImage = draft;
            ArrayList<Uri> selected = new ArrayList<>();
            if (draft.imageUri != null) selected.add(draft.imageUri);
            voteOptionImagePicker.launch(MediaPickerActivity.imageIntent(this, 1, selected));
        });
        container.addView(row, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));
    }

    private List<VoteOptionDraft> collectVoteOptions(LinearLayout rows) {
        List<VoteOptionDraft> values = new ArrayList<>();
        for (int index = 0; index < rows.getChildCount(); index++) {
            Object tag = rows.getChildAt(index).getTag();
            if (!(tag instanceof VoteOptionDraft)) continue;
            VoteOptionDraft draft = (VoteOptionDraft) tag;
            draft.text = draft.input.getText().toString().trim();
            if (!draft.text.isEmpty()) values.add(draft);
        }
        return values;
    }

    private void uploadVoteOptionImages(JsonObject body, List<VoteOptionDraft> options, int index,
                                        AlertDialog dialog) {
        if (binding == null || isFinishing() || isDestroyed()) return;
        if (index >= options.size()) {
            JsonArray values = new JsonArray();
            for (int position = 0; position < options.size(); position++) {
                VoteOptionDraft draft = options.get(position);
                JsonObject option = new JsonObject();
                option.addProperty("option_text", draft.text);
                option.addProperty("image_url", draft.imageUrl);
                option.addProperty("sort_order", position);
                values.add(option);
            }
            body.add("options", values);
            binding.progress.setVisibility(View.INVISIBLE);
            dialog.dismiss();
            post(base() + "/votes", body);
            return;
        }
        VoteOptionDraft draft = options.get(index);
        if (draft.imageUri == null || !draft.imageUrl.isEmpty()) {
            uploadVoteOptionImages(body, options, index + 1, dialog);
            return;
        }
        UriFile file = uriFile(draft.imageUri);
        if (!UploadPolicyStore.accepts(this, "image", file.size)) {
            restoreVoteDialog(dialog, UploadPolicyStore.rejectionMessage(this, "image", file.size));
            return;
        }
        binding.progress.setVisibility(View.VISIBLE);
        Map<String, String> fields = new LinkedHashMap<>();
        fields.put("scene", "poll_option");
        voteOptionUploadRequest = AppAccess.from(this).repository().upload(
            "/api/user/uploads", file.name, file.mime,
            new ContentUriRequestBody(getContentResolver(), draft.imageUri, file.mime, file.size),
            fields, result -> {
                voteOptionUploadRequest = null;
                if (binding == null || isFinishing() || isDestroyed()) return;
                if (!result.isSuccessful()) {
                    restoreVoteDialog(dialog, result.message().isEmpty() ? "选项图片上传失败" : result.message());
                    return;
                }
                draft.imageUrl = Jsons.string(result.dataObject(), "file_url");
                uploadVoteOptionImages(body, options, index + 1, dialog);
            });
    }

    private void restoreVoteDialog(AlertDialog dialog, String message) {
        binding.progress.setVisibility(View.INVISIBLE);
        if (dialog.isShowing()) {
            dialog.getButton(AlertDialog.BUTTON_POSITIVE).setEnabled(true);
            dialog.getButton(AlertDialog.BUTTON_POSITIVE).setText("发布");
        }
        toast(message);
    }

    private UriFile uriFile(Uri uri) {
        String name = "投票选项图片";
        long size = -1;
        try (Cursor cursor = getContentResolver().query(uri,
            new String[]{OpenableColumns.DISPLAY_NAME, OpenableColumns.SIZE}, null, null, null)) {
            if (cursor != null && cursor.moveToFirst()) {
                int nameColumn = cursor.getColumnIndex(OpenableColumns.DISPLAY_NAME);
                int sizeColumn = cursor.getColumnIndex(OpenableColumns.SIZE);
                if (nameColumn >= 0 && !cursor.isNull(nameColumn)) name = cursor.getString(nameColumn);
                if (sizeColumn >= 0 && !cursor.isNull(sizeColumn)) size = cursor.getLong(sizeColumn);
            }
        } catch (RuntimeException ignored) { }
        String mime = getContentResolver().getType(uri);
        if (mime == null || !mime.startsWith("image/")) mime = "image/jpeg";
        return new UriFile(name, mime, size);
    }

    private void albumActions() {
        new YiyunyingDialogBuilder(this)
            .setTitle(spacePrefix() + "相册")
            .setItems(new String[]{"直接上传图片或视频", "新建相册"}, (dialog, which) -> {
                if (which == 0) selectAlbumForUpload();
                else textForm("新建" + spacePrefix() + "相册", "相册名称", "相册说明", (first, second) -> createAlbum(first, second, false));
            })
            .setNegativeButton("取消", null)
            .show();
    }

    private void selectAlbumForUpload() {
        if (items.isEmpty()) {
            createAlbum(spacePrefix() + "共享相册", "未指定相册时直接上传的" + entityLabel() + "媒体", true);
            return;
        }
        String[] names = new String[items.size()];
        for (int index = 0; index < items.size(); index++) names[index] = first(items.get(index), "name", "title");
        new YiyunyingDialogBuilder(this)
            .setTitle("选择上传到哪个相册")
            .setItems(names, (dialog, which) -> {
                targetAlbumId = Jsons.longValue(items.get(which), "id");
                uploadMode = "photo";
                mediaPicker.launch(MediaPickerActivity.intent(this, true));
            })
            .setNegativeButton("取消", null)
            .show();
    }

    private void postAlbum(String name, String description) {
        createAlbum(name, description, false);
    }

    private void createAlbum(String name, String description, boolean openUpload) {
        if (actionRequest != null) return;
        JsonObject body = new JsonObject(); body.addProperty("name", name); body.addProperty("description", description);
        binding.progress.setVisibility(View.VISIBLE);
        actionRequest = AppAccess.from(this).repository().post(base() + "/albums", body, result -> {
            actionRequest = null;
            if (binding == null) return;
            binding.progress.setVisibility(View.INVISIBLE);
            if (!result.isSuccessful()) { toast(result.message().isEmpty() ? spacePrefix() + "相册创建失败" : result.message()); return; }
            toast(result.message().isEmpty() ? spacePrefix() + "相册已创建" : result.message());
            long albumId = Jsons.longValue(result.dataObject(), "album_id");
            load(); loadRoom();
            if (openUpload && albumId > 0) {
                targetAlbumId = albumId;
                uploadMode = "photo";
                mediaPicker.launch(MediaPickerActivity.intent(this, true));
            }
        });
    }

    private void postSimple(String endpoint, String title, String description) {
        JsonObject body = new JsonObject(); body.addProperty("title", title); body.addProperty("description", description);
        post(base() + "/" + endpoint, body);
    }

    private void selectedFiles(List<Uri> uris) {
        if (uris == null || uris.isEmpty() || uploadRequest != null || actionRequest != null) return;
        uploadQueue.clear();
        uploadQueue.addAll(uris);
        uploadTotal = uris.size();
        uploadCompleted = 0;
        uploadNext();
    }

    private void uploadNext() {
        if (uploadRequest != null || actionRequest != null) return;
        Uri uri = uploadQueue.pollFirst();
        if (uri == null) {
            if (binding != null) {
                binding.progress.setVisibility(View.INVISIBLE);
                toast(uploadCompleted == uploadTotal
                    ? "已完成 " + uploadCompleted + " 项上传"
                    : "已上传 " + uploadCompleted + " 项，共选择 " + uploadTotal + " 项");
                load();
                loadRoom();
            }
            return;
        }
        selectedFile(uri);
    }

    private void selectedFile(Uri uri) {
        if (uri == null || uploadRequest != null) return;
        String name = "本地文件"; long size = -1;
        try (Cursor cursor = getContentResolver().query(uri, null, null, null, null)) {
            if (cursor != null && cursor.moveToFirst()) {
                int n = cursor.getColumnIndex(OpenableColumns.DISPLAY_NAME), s = cursor.getColumnIndex(OpenableColumns.SIZE);
                if (n >= 0 && !cursor.isNull(n)) name = cursor.getString(n); if (s >= 0 && !cursor.isNull(s)) size = cursor.getLong(s);
            }
        }
        String mime = getContentResolver().getType(uri); if (mime == null) mime = "application/octet-stream";
        String mediaType = "photo".equals(uploadMode) ? "image"
            : (mime.startsWith("image/") ? "image" : (mime.startsWith("video/") ? "video" : (mime.startsWith("audio/") ? "audio" : "file")));
        if (!UploadPolicyStore.accepts(this, mediaType, size)) {
            toast(UploadPolicyStore.rejectionMessage(this, mediaType, size));
            uploadNext();
            return;
        }
        ContentUriRequestBody body = new ContentUriRequestBody(getContentResolver(), uri, mime, size);
        Map<String, String> fields = new LinkedHashMap<>(); fields.put("scene", "photo".equals(uploadMode) ? spacePrefix() + "相册" : fileRootName());
        binding.progress.setVisibility(View.VISIBLE);
        String fileName = name, fileMime = mime; long fileSize = size;
        uploadRequest = AppAccess.from(this).repository().upload("/api/user/uploads", name, mime, body, fields, result -> {
            uploadRequest = null; if (binding == null) return;
            if (!result.isSuccessful()) {
                toast(result.message().isEmpty() ? "文件上传失败" : result.message());
                uploadNext();
                return;
            }
            JsonObject payload = new JsonObject();
            if ("photo".equals(uploadMode)) {
                payload.addProperty("image_url", Jsons.string(result.dataObject(), "file_url"));
                payload.addProperty("media_type", fileMime.startsWith("video/") ? "video" : "image");
                payload.addProperty("mime_type", fileMime);
                payload.addProperty("size_bytes", fileSize);
                payload.addProperty("caption", fileName);
                postUploadedItem(base() + "/albums/" + targetAlbumId + "/photos", payload);
            } else {
                payload.addProperty("name", fileName); payload.addProperty("file_url", Jsons.string(result.dataObject(), "file_url"));
                payload.addProperty("mime_type", fileMime); payload.addProperty("size_bytes", fileSize);
                payload.addProperty("parent_id", currentFolderId);
                postUploadedItem(base() + "/files", payload);
            }
        });
    }

    private void postUploadedItem(String path, JsonObject payload) {
        actionRequest = AppAccess.from(this).repository().post(path, payload, result -> {
            actionRequest = null;
            if (binding == null) return;
            if (result.isSuccessful()) uploadCompleted++;
            else toast(result.message().isEmpty() ? "上传内容保存失败" : result.message());
            uploadNext();
        });
    }

    private void post(String path, JsonObject body) {
        if (actionRequest != null) return;
        binding.progress.setVisibility(View.VISIBLE);
        actionRequest = AppAccess.from(this).repository().post(path, body, result -> {
            actionRequest = null; if (binding == null) return; binding.progress.setVisibility(View.INVISIBLE);
            toast(result.isSuccessful() ? (result.message().isEmpty() ? "操作成功" : result.message()) : (result.message().isEmpty() ? "操作失败" : result.message()));
            if (result.isSuccessful()) { load(); loadRoom(); }
        });
    }

    private void deleteItem(JsonObject item) {
        String path;
        String target;
        if ("files".equals(section)) {
            path = base() + "/files/" + Jsons.longValue(item, "id");
            target = fileRootName();
        } else if ("albums".equals(section)) {
            path = base() + "/albums/" + Jsons.longValue(item, "id");
            target = spacePrefix() + "相册";
        } else if ("votes".equals(section)) {
            path = base() + "/votes/" + Jsons.longValue(item, "id");
            target = spacePrefix() + "投票";
        } else if ("solitaires".equals(section)) {
            path = base() + "/solitaires/" + Jsons.longValue(item, "id");
            target = spacePrefix() + "接龙";
        } else {
            openItem(item);
            return;
        }
        String finalPath = path;
        new YiyunyingDialogBuilder(this)
            .setTitle("删除" + target)
            .setMessage("删除后" + entityLabel() + "内会生成操作通知，确定继续吗？")
            .setPositiveButton("删除", (dialog, which) -> {
                binding.progress.setVisibility(View.VISIBLE);
                actionRequest = AppAccess.from(this).repository().delete(finalPath, new JsonObject(), result -> {
                    actionRequest = null;
                    if (binding == null) return;
                    binding.progress.setVisibility(View.INVISIBLE);
                    toast(result.isSuccessful() ? (result.message().isEmpty() ? "删除成功" : result.message())
                        : (result.message().isEmpty() ? "删除失败" : result.message()));
                    if (result.isSuccessful()) { load(); loadRoom(); }
                });
            })
            .setNegativeButton("取消", null)
            .show();
    }

    private void showItemActions(JsonObject item) {
        if ("members".equals(section)) {
            showMemberActions(item);
            return;
        }
        if (!bool(item, "can_delete")) {
            openItem(item);
            return;
        }
        new YiyunyingDialogBuilder(this)
            .setTitle(sectionName() + "管理")
            .setItems(new String[]{"打开", "删除"}, (dialog, which) -> {
                if (which == 0) openItem(item);
                else deleteItem(item);
            })
            .setNegativeButton("取消", null)
            .show();
    }

    private void showMemberActions(JsonObject member) {
        long targetId = Jsons.longValue(member, "user_id");
        long selfId = AppAccess.from(this).session().actorId();
        String targetRole = Jsons.string(member, "role");
        String setAdminAction = "设为" + adminRoleName();
        String unsetAdminAction = "取消" + adminRoleName();
        String transferAction = "转让" + ownerRoleName();
        String removeAction = "移出" + entityLabel();
        List<String> actions = new ArrayList<>();
        actions.add("查看个人主页");
        boolean owner = "owner".equals(currentRole);
        boolean manager = owner || "admin".equals(currentRole);
        boolean manageable = manager && targetId != selfId && !"owner".equals(targetRole)
            && (owner || !"admin".equals(targetRole));
        if (owner && targetId != selfId && !"owner".equals(targetRole)) {
            actions.add("admin".equals(targetRole) ? unsetAdminAction : setAdminAction);
            actions.add(transferAction);
        }
        if (manageable) {
            actions.add("禁言管理");
            actions.add(removeAction);
        }
        new YiyunyingDialogBuilder(this)
            .setTitle(first(member, "nickname", "account") + " · " + roleName(targetRole))
            .setItems(actions.toArray(new String[0]), (dialog, which) -> {
                String action = actions.get(which);
                if ("查看个人主页".equals(action)) UserProfileActivity.open(this, targetId);
                else if (setAdminAction.equals(action)) updateMemberRole(targetId, "admin");
                else if (unsetAdminAction.equals(action)) updateMemberRole(targetId, "member");
                else if (transferAction.equals(action)) confirmTransfer(member);
                else if ("禁言管理".equals(action)) showMuteActions(member);
                else if (removeAction.equals(action)) confirmRemoveMember(member);
            })
            .setNegativeButton("取消", null)
            .show();
    }

    private void updateMemberRole(long userId, String role) {
        JsonObject body = new JsonObject();
        body.addProperty("role", role);
        executeMemberAction("put", base() + "/members/" + userId, body);
    }

    private void showMuteActions(JsonObject member) {
        String[] actions = new String[]{"解除禁言", "禁言 10 分钟", "禁言 1 小时", "禁言 1 天"};
        new YiyunyingDialogBuilder(this)
            .setTitle("禁言 " + first(member, "nickname", "account"))
            .setItems(actions, (dialog, which) -> {
                JsonObject body = new JsonObject();
                if (which == 0) body.add("mute_until", JsonNull.INSTANCE);
                else {
                    long[] durations = new long[]{0L, 10L * 60_000L, 60L * 60_000L, 24L * 60L * 60_000L};
                    java.text.SimpleDateFormat format = new java.text.SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.CHINA);
                    body.addProperty("mute_until", format.format(new java.util.Date(System.currentTimeMillis() + durations[which])));
                }
                executeMemberAction("put", base() + "/members/" + Jsons.longValue(member, "user_id") + "/mute", body);
            })
            .setNegativeButton("取消", null)
            .show();
    }

    private void confirmRemoveMember(JsonObject member) {
        new YiyunyingDialogBuilder(this)
            .setTitle("移出" + entityLabel())
            .setMessage("确定将“" + first(member, "nickname", "account") + "”移出" + entityLabel() + "吗？")
            .setPositiveButton("移出", (dialog, which) -> executeMemberAction(
                "delete", base() + "/members/" + Jsons.longValue(member, "user_id"), new JsonObject()))
            .setNegativeButton("取消", null)
            .show();
    }

    private void confirmTransfer(JsonObject member) {
        new YiyunyingDialogBuilder(this)
            .setTitle("转让" + ownerRoleName())
            .setMessage("转让后你将成为普通" + memberLabel() + "。确定将" + ownerRoleName() + "转让给“"
                + first(member, "nickname", "account") + "”吗？")
            .setPositiveButton("确认转让", (dialog, which) -> {
                JsonObject body = new JsonObject();
                body.addProperty("new_owner_user_id", Jsons.longValue(member, "user_id"));
                executeMemberAction("post", base() + "/transfer", body);
            })
            .setNegativeButton("取消", null)
            .show();
    }

    private void executeMemberAction(String method, String path, JsonObject body) {
        if (actionRequest != null) { toast("请等待当前操作完成"); return; }
        binding.progress.setVisibility(View.VISIBLE);
        xyz.jjmxg.yiyunying.data.repository.YiyunyingRepository repository = AppAccess.from(this).repository();
        xyz.jjmxg.yiyunying.data.api.ApiCallback callback = result -> {
            actionRequest = null;
            if (binding == null) return;
            binding.progress.setVisibility(View.INVISIBLE);
            toast(result.isSuccessful()
                ? (result.message().isEmpty() ? "成员设置已更新" : result.message())
                : (result.message().isEmpty() ? "成员设置失败" : result.message()));
            if (result.isSuccessful()) { load(); loadRoom(); }
        };
        if ("delete".equals(method)) actionRequest = repository.delete(path, body, callback);
        else if ("post".equals(method)) actionRequest = repository.post(path, body, callback);
        else actionRequest = repository.put(path, body, callback);
    }

    private void openItem(JsonObject item) {
        if ("members".equals(section)) { UserProfileActivity.open(this, Jsons.longValue(item, "user_id")); return; }
        if ("files".equals(section)) {
            if (bool(item, "is_folder")) { enterFolder(item); return; }
            openGroupFile(item);
            return;
        }
        if ("albums".equals(section)) { openAlbum(item); return; }
        if ("votes".equals(section)) { openVote(item); return; }
        openSolitaire(item);
    }

    private void enterFolder(JsonObject folder) {
        folderParents.push(currentFolderId);
        folderParentNames.push(currentFolderName);
        currentFolderId = Jsons.longValue(folder, "id");
        currentFolderName = Jsons.string(folder, "name");
        updateFolderNavigation();
        load();
    }

    private void openGroupFile(JsonObject item) {
        JsonObject file = item.deepCopy();
        file.addProperty("original_name", Jsons.string(item, "name"));
        if (actionRequest != null) { toast("请等待当前操作完成"); return; }
        actionRequest = AppAccess.from(this).repository().post(
            base() + "/files/" + Jsons.longValue(item, "id") + "/download", new JsonObject(), result -> {
                actionRequest = null;
                if (binding == null) return;
                if (result.isSuccessful()) file.addProperty("download_count", Jsons.longValue(result.dataObject(), "download_count"));
                else toast(result.message().isEmpty() ? "下载次数暂未同步，仍可预览文件" : result.message());
                FilePreviewActivity.open(this, file);
            });
    }

    private void navigateBack() {
        if ("files".equals(section) && currentFolderId > 0 && !folderParents.isEmpty()) {
            currentFolderId = folderParents.pop();
            currentFolderName = folderParentNames.isEmpty() ? fileRootName() : folderParentNames.pop();
            updateFolderNavigation();
            load();
            return;
        }
        finish();
    }

    private void updateFolderNavigation() {
        binding.toolbar.setSubtitle("files".equals(section) && currentFolderId > 0 ? fileRootName() + " / " + currentFolderName : null);
    }

    private void showGroupQr() {
        if (binding == null || groupNumber.isEmpty()) { toast(entityLabel() + "信息尚未加载完成"); return; }
        String payload = "yiyunying://group/" + roomId + "?uid=" + groupNumber;
        String displayName = String.valueOf(binding.groupName.getText());
        try {
            Bitmap bitmap = new BarcodeEncoder().encodeBitmap(payload, BarcodeFormat.QR_CODE, 800, 800);
            String details = entityNumberLabel() + "：" + groupNumber
                + (groupCreatedAt.isEmpty() ? "" : "\n创建时间：" + groupCreatedAt);
            QrShareDialog.show(this, bitmap, displayName, details, "复制" + entityNumberLabel(),
                (dialog, which) -> {
                    Intent intent = new Intent(Intent.ACTION_SEND);
                    intent.setType("text/plain");
                    intent.putExtra(Intent.EXTRA_TEXT, "邀请你加入" + entityLabel() + "「" + displayName + "」\n"
                        + entityNumberLabel() + "：" + groupNumber + "\n" + payload);
                    startActivity(Intent.createChooser(intent, "分享" + entityLabel()));
                },
                (dialog, which) -> {
                    ClipboardManager clipboard = (ClipboardManager) getSystemService(Context.CLIPBOARD_SERVICE);
                    clipboard.setPrimaryClip(ClipData.newPlainText("易运盈" + entityNumberLabel(), groupNumber));
                    toast(entityNumberLabel() + "已复制");
                });
        } catch (Exception exception) {
            toast(entityLabel() + "二维码生成失败，请稍后重试");
        }
    }

    private void openAlbum(JsonObject album) {
        GroupAlbumDetailActivity.open(this, roomId, Jsons.longValue(album, "id"), Jsons.string(album, "name"));
    }

    private void showAlbumInfo(JsonObject album, List<JsonObject> media) {
        String creator = first(album, "creator_nickname", "creator_account");
        String description = Jsons.string(album, "description");
        String message = "创建者：" + creator
            + "\n媒体数量：" + media.size()
            + "\n创建时间：" + Jsons.string(album, "created_at")
            + "\n\n" + (description.isEmpty() ? "暂无相册说明" : description);
        new YiyunyingDialogBuilder(this)
            .setBusinessTitle(Jsons.string(album, "name"))
            .setMessage(message)
            .setPositiveButton("确定", null)
            .show();
    }

    private void openVote(JsonObject item) {
        binding.progress.setVisibility(View.VISIBLE);
        request = AppAccess.from(this).repository().get(base() + "/votes/" + Jsons.longValue(item, "id"), new LinkedHashMap<>(), result -> {
            request = null;
            if (binding == null) return;
            binding.progress.setVisibility(View.INVISIBLE);
            if (!result.isSuccessful()) { toast(result.message().isEmpty() ? spacePrefix() + "投票详情加载失败" : result.message()); return; }
            JsonObject vote = Jsons.object(result.dataObject(), "vote");
            JsonArray options = Jsons.array(vote, "options");
            boolean multipleChoice = bool(vote, "multiple_choice")
                || bool(vote, "multi_select") || bool(vote, "allow_multiple");
            JsonArray selectedIds = Jsons.array(vote, "selected_option_ids");
            ScrollView scroll = new ScrollView(this);
            LinearLayout content = form();
            scroll.addView(content);
            String description = Jsons.string(vote, "description");
            if (!description.isEmpty()) {
                TextView descriptionView = new TextView(this);
                descriptionView.setText(description);
                descriptionView.setTextSize(15f);
                descriptionView.setTextColor(getColor(R.color.on_surface));
                descriptionView.setPadding(dp(4), dp(2), dp(4), dp(10));
                content.addView(descriptionView);
            }
            String rule = multipleChoice
                ? "可选择 " + Jsons.longValue(vote, "min_select") + "-" + Jsons.longValue(vote, "max_select") + " 项"
                : "请选择 1 项";
            TextView ruleView = new TextView(this);
            String endsAt = Jsons.string(vote, "ends_at");
            ruleView.setText(rule
                + (bool(vote, "allow_change") ? " · 可修改" : " · 提交后不可修改")
                + (bool(vote, "anonymous") ? " · 匿名" : "")
                + (endsAt.isEmpty() ? "" : "\n截止时间：" + endsAt));
            ruleView.setTextColor(getColor(R.color.primary));
            ruleView.setPadding(dp(4), dp(2), dp(4), dp(8));
            content.addView(ruleView);
            List<CompoundButton> controls = new ArrayList<>();
            for (JsonElement element : options) {
                if (!element.isJsonObject()) continue;
                JsonObject option = element.getAsJsonObject();
                long optionId = Jsons.longValue(option, "id");
                LinearLayout optionContent = new LinearLayout(this);
                optionContent.setOrientation(LinearLayout.VERTICAL);
                optionContent.setPadding(dp(10), dp(8), dp(10), dp(8));
                String imageUrl = Jsons.string(option, "image_url");
                if (!imageUrl.isEmpty()) {
                    ImageView preview = new ImageView(this);
                    preview.setScaleType(ImageView.ScaleType.CENTER_CROP);
                    preview.setContentDescription("投票选项图片");
                    optionContent.addView(preview, new LinearLayout.LayoutParams(
                        ViewGroup.LayoutParams.MATCH_PARENT, dp(132)));
                    Glide.with(this)
                        .load(ImageLoader.get().absoluteUrl(this, imageUrl))
                        .centerCrop()
                        .into(preview);
                }
                CompoundButton control = multipleChoice ? new MaterialCheckBox(this) : new RadioButton(this);
                String label = Jsons.string(option, "option_text");
                if (bool(vote, "results_visible")) label += "  ·  " + Jsons.longValue(option, "vote_count") + " 票";
                control.setText(label);
                control.setTag(optionId);
                control.setMinHeight(dp(52));
                control.setChecked(containsId(selectedIds, optionId));
                if (!multipleChoice) control.setOnCheckedChangeListener((button, checked) -> {
                    if (!checked) return;
                    for (CompoundButton other : controls) {
                        if (other != button && other.isChecked()) other.setChecked(false);
                    }
                });
                controls.add(control);
                optionContent.addView(control);
                MaterialCardView card = new MaterialCardView(this);
                card.setCardElevation(0f);
                card.setStrokeWidth(dp(1));
                card.setStrokeColor(getColor(R.color.outline_variant));
                card.setRadius(dp(8));
                card.addView(optionContent);
                LinearLayout.LayoutParams params = new LinearLayout.LayoutParams(
                    ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT);
                params.bottomMargin = dp(8);
                content.addView(card, params);
            }
            boolean active = "active".equals(Jsons.string(vote, "status"));
            AlertDialog dialog = new YiyunyingDialogBuilder(this)
                .setBusinessTitle(Jsons.string(vote, "title"))
                .setView(scroll)
                .setPositiveButton(active ? (bool(vote, "voted") ? "修改投票" : "提交投票") : "投票已结束", null)
                .setNegativeButton("关闭", null)
                .create();
            dialog.setOnShowListener(ignored -> {
                dialog.getButton(AlertDialog.BUTTON_POSITIVE).setEnabled(active);
                dialog.getButton(AlertDialog.BUTTON_POSITIVE).setOnClickListener(view -> {
                    JsonArray ids = new JsonArray();
                    for (CompoundButton control : controls) if (control.isChecked()) ids.add((Long) control.getTag());
                    int minimum = Math.max(1, (int) Jsons.longValue(vote, "min_select"));
                    int maximum = Math.max(minimum, (int) Jsons.longValue(vote, "max_select"));
                    if (ids.size() < minimum || ids.size() > maximum) {
                        toast("请选择 " + minimum + " 至 " + maximum + " 个选项");
                        return;
                    }
                    JsonObject body = new JsonObject(); body.add("option_ids", ids);
                    dialog.dismiss();
                    post(base() + "/votes/" + Jsons.longValue(vote, "id") + "/submit", body);
                });
            });
            dialog.show();
        });
    }

    private static boolean containsId(JsonArray values, long id) {
        for (JsonElement value : values) {
            try { if (value.getAsLong() == id) return true; }
            catch (RuntimeException ignored) { }
        }
        return false;
    }

    private void openSolitaire(JsonObject item) {
        binding.progress.setVisibility(View.VISIBLE);
        request = AppAccess.from(this).repository().get(base() + "/solitaires/" + Jsons.longValue(item, "id"), new LinkedHashMap<>(), result -> {
            request = null;
            if (binding == null) return;
            binding.progress.setVisibility(View.INVISIBLE);
            if (!result.isSuccessful()) { toast(result.message().isEmpty() ? "接龙详情加载失败" : result.message()); return; }
            showSolitaire(Jsons.object(result.dataObject(), "solitaire"));
        });
    }

    private void showSolitaire(JsonObject solitaire) {
        JsonArray entries = Jsons.array(solitaire, "entries");
        LinearLayout content = form();
        boolean active = "active".equals(Jsons.string(solitaire, "status"));
        String endsAt = Jsons.string(solitaire, "ends_at");
        TextView status = new TextView(this);
        status.setText((active ? "进行中" : "已结束") + "  ·  "
            + (endsAt.isEmpty() ? "长期有效" : "截止 " + endsAt));
        status.setTextSize(13f);
        status.setTextColor(getColor(active ? R.color.primary : R.color.on_surface_variant));
        status.setPadding(dp(4), 0, dp(4), dp(10));
        content.addView(status);
        String description = Jsons.string(solitaire, "description");
        if (!description.isEmpty()) {
            TextView intro = new TextView(this);
            intro.setText(description);
            intro.setTextSize(14f);
            intro.setTextColor(getColor(R.color.on_surface_variant));
            intro.setPadding(dp(4), 0, dp(4), dp(10));
            content.addView(intro);
        }
        if (entries.isEmpty()) {
            TextView empty = new TextView(this);
            empty.setText("暂时还没有人参与，你可以成为第 1 位");
            empty.setTextColor(getColor(R.color.on_surface_variant));
            empty.setPadding(dp(12), dp(18), dp(12), dp(18));
            content.addView(empty);
        }
        for (int index = 0; index < entries.size(); index++) {
            JsonObject entry = entries.get(index).getAsJsonObject();
            MaterialCardView card = new MaterialCardView(this);
            card.setCardElevation(0f);
            card.setStrokeWidth(dp(1));
            card.setStrokeColor(getColor(R.color.outline_variant));
            card.setRadius(dp(8));
            LinearLayout entryContent = new LinearLayout(this);
            entryContent.setOrientation(LinearLayout.VERTICAL);
            entryContent.setPadding(dp(14), dp(10), dp(14), dp(10));
            TextView member = new TextView(this);
            member.setText((index + 1) + "  " + first(entry, "nickname", "account"));
            member.setTextSize(12.5f);
            member.setTextColor(getColor(R.color.primary));
            TextView value = new TextView(this);
            value.setText(Jsons.string(entry, "content"));
            value.setTextSize(15f);
            value.setTextColor(getColor(R.color.on_surface));
            value.setPadding(0, dp(4), 0, 0);
            TextView time = new TextView(this);
            time.setText(Jsons.string(entry, "updated_at"));
            time.setTextSize(11f);
            time.setTextColor(getColor(R.color.on_surface_variant));
            time.setPadding(0, dp(4), 0, 0);
            entryContent.addView(member);
            entryContent.addView(value);
            if (!Jsons.string(entry, "updated_at").isEmpty()) entryContent.addView(time);
            card.addView(entryContent);
            LinearLayout.LayoutParams cardParams = new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT);
            cardParams.bottomMargin = dp(8);
            content.addView(card, cardParams);
        }
        EditText input = input("填写本次接龙内容");
        input.setMinLines(2);
        content.addView(input);
        ScrollView scroll = new ScrollView(this);
        scroll.addView(content);
        AlertDialog dialog = new YiyunyingDialogBuilder(this)
            .setBusinessTitle(Jsons.string(solitaire, "title") + " · " + entries.size() + " 人")
            .setView(scroll)
            .setPositiveButton(active ? "提交接龙" : "接龙已结束", null)
            .setNegativeButton("关闭", null)
            .create();
        dialog.setOnShowListener(ignored -> {
            dialog.getButton(AlertDialog.BUTTON_POSITIVE).setEnabled(active);
            dialog.getButton(AlertDialog.BUTTON_POSITIVE).setOnClickListener(view -> {
                String entryContent = input.getText().toString().trim();
                if (entryContent.isEmpty()) { toast("请填写接龙内容"); return; }
                JsonObject body = new JsonObject(); body.addProperty("content", entryContent);
                dialog.dismiss();
                post(base() + "/solitaires/" + Jsons.longValue(solitaire, "id") + "/join", body);
            });
        });
        dialog.show();
    }

    private void textForm(String title, String firstHint, String secondHint, TextResult result) {
        LinearLayout form = form(); EditText first = input(firstHint), second = input(secondHint); form.addView(first); form.addView(second);
        new YiyunyingDialogBuilder(this).setTitle(title).setView(form).setPositiveButton("确定", (dialog, which) -> {
            if (first.getText().toString().trim().isEmpty()) { toast("请填写" + firstHint); return; }
            result.onResult(first.getText().toString().trim(), second.getText().toString().trim());
        }).setNegativeButton("取消", null).show();
    }

    private LinearLayout form() { LinearLayout form = new LinearLayout(this); form.setOrientation(LinearLayout.VERTICAL); form.setPadding(dp(22), dp(4), dp(22), 0); return form; }
    private EditText input(String hint) { EditText input = new EditText(this); input.setHint(hint); input.setPadding(dp(8), dp(10), dp(8), dp(10)); return input; }
    private int selectedDurationIndex(long[] choices, long selected) {
        for (int index = 0; index < choices.length; index++) if (choices[index] == selected) return index;
        return 3;
    }
    private String futureDateTime(long hours) {
        Calendar calendar = Calendar.getInstance();
        calendar.setTime(new Date());
        calendar.add(Calendar.HOUR_OF_DAY, (int) Math.min(Integer.MAX_VALUE, hours));
        return new SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.US).format(calendar.getTime());
    }
    private boolean isChatRoom() { return "chat_room".equals(roomKind); }
    private String entityLabel() { return isChatRoom() ? "聊天室" : "群聊"; }
    private String entityNumberLabel() { return isChatRoom() ? "聊天室号" : "群号"; }
    private String memberLabel() { return isChatRoom() ? "聊天室成员" : "群成员"; }
    private String ownerRoleName() { return isChatRoom() ? "聊天室创建者" : "群主"; }
    private String adminRoleName() { return isChatRoom() ? "聊天室管理员" : "群管理员"; }
    private String fileRootName() { return isChatRoom() ? "聊天室文件" : "群文件"; }
    private String spacePrefix() { return isChatRoom() ? "聊天室" : "群"; }

    private String base() { return "/api/user/chat-rooms/" + roomId; }
    private void toast(String message) {
        if (binding == null || isFinishing() || isDestroyed()) return;
        Snackbar.make(binding.getRoot(), message == null || message.isEmpty() ? "操作未完成" : message, Snackbar.LENGTH_LONG).show();
    }
    private int dp(int value) { return Math.round(value * getResources().getDisplayMetrics().density); }
    private String roleName(String role) { return "owner".equals(role) ? ownerRoleName() : ("admin".equals(role) ? adminRoleName() : memberLabel()); }
    private interface TextResult { void onResult(String first, String second); }

    private static final class VoteOptionDraft {
        final LinearLayout root;
        final EditText input;
        final MaterialButton imageButton;
        final ImageView imagePreview;
        Uri imageUri;
        String imageUrl = "";
        String text = "";

        VoteOptionDraft(LinearLayout root, EditText input, MaterialButton imageButton,
                        ImageView imagePreview) {
            this.root = root;
            this.input = input;
            this.imageButton = imageButton;
            this.imagePreview = imagePreview;
        }
    }

    private static final class UriFile {
        final String name;
        final String mime;
        final long size;

        UriFile(String name, String mime, long size) {
            this.name = name;
            this.mime = mime;
            this.size = size;
        }
    }

    @Override protected void onDestroy() {
        if (request != null) request.cancel();
        if (roomRequest != null) roomRequest.cancel();
        if (actionRequest != null) actionRequest.cancel();
        if (uploadRequest != null) uploadRequest.cancel();
        if (voteOptionUploadRequest != null) voteOptionUploadRequest.cancel();
        if (avatarUploadRequest != null) avatarUploadRequest.cancel();
        if (avatarPolicyRequest != null) avatarPolicyRequest.cancel();
        binding = null; super.onDestroy();
    }

    private final class SpaceAdapter extends RecyclerView.Adapter<SpaceAdapter.Holder> {
        private String renderedSection = "";

        SpaceAdapter() {
            setHasStableIds(true);
        }

        void submit(String nextSection, List<JsonObject> incoming) {
            List<JsonObject> next = incoming == null ? new ArrayList<>() : new ArrayList<>(incoming);
            if (!renderedSection.equals(nextSection) || items.size() > 200 || next.size() > 200) {
                renderedSection = nextSection;
                items.clear();
                items.addAll(next);
                notifyDataSetChanged();
                return;
            }
            List<JsonObject> previous = new ArrayList<>(items);
            DiffUtil.DiffResult diff = DiffUtil.calculateDiff(new DiffUtil.Callback() {
                @Override public int getOldListSize() { return previous.size(); }
                @Override public int getNewListSize() { return next.size(); }
                @Override public boolean areItemsTheSame(int oldPosition, int newPosition) {
                    return identity(previous.get(oldPosition)).equals(identity(next.get(newPosition)));
                }
                @Override public boolean areContentsTheSame(int oldPosition, int newPosition) {
                    return previous.get(oldPosition).equals(next.get(newPosition));
                }
            }, false);
            items.clear();
            items.addAll(next);
            diff.dispatchUpdatesTo(this);
        }

        @Override public long getItemId(int position) {
            String value = identity(items.get(position));
            long hash = 1125899906842597L;
            for (int index = 0; index < value.length(); index++) hash = 31L * hash + value.charAt(index);
            return hash;
        }

        private String identity(JsonObject item) {
            String id = Jsons.string(item, "id");
            if (id.isEmpty() && "members".equals(renderedSection)) id = Jsons.string(item, "user_id");
            if (id.isEmpty()) id = value(item, "uid", "group_number", "name", "title");
            if (id.isEmpty()) id = item.toString();
            return renderedSection + ":" + id;
        }

        @NonNull @Override public Holder onCreateViewHolder(@NonNull ViewGroup parent, int type) { return new Holder(ItemRecordBinding.inflate(LayoutInflater.from(parent.getContext()), parent, false)); }
        @Override public void onBindViewHolder(@NonNull Holder holder, int position) {
            JsonObject item = items.get(position); String title = title(item); holder.binding.title.setText(title);
            String avatar = "members".equals(renderedSection) ? value(item, "avatar", "user_avatar", "sender_avatar") : "";
            if (!avatar.isEmpty()) {
                holder.binding.avatar.setVisibility(View.GONE);
                holder.binding.avatarImage.setVisibility(View.VISIBLE);
                ImageLoader.get().load(ImageLoader.get().absoluteUrl(GroupSpaceActivity.this, avatar),
                    holder.binding.avatarImage, R.drawable.ic_person);
            } else {
                holder.binding.avatarImage.setVisibility(View.GONE);
                holder.binding.avatar.setVisibility(View.VISIBLE);
                holder.binding.avatar.setText(title.isEmpty() ? "人" : title.substring(0, 1));
            }
            holder.binding.subtitle.setText(subtitle(item));
            holder.binding.metadata.setText("members".equals(renderedSection) ? Jsons.string(item, "joined_at") : Jsons.string(item, "created_at")); holder.binding.moreButton.setVisibility(View.GONE); holder.binding.selectionCheck.setVisibility(View.GONE);
            holder.binding.getRoot().setOnClickListener(view -> openItem(item));
            holder.binding.getRoot().setOnLongClickListener(view -> { showItemActions(item); return true; });
        }
        @Override public int getItemCount() { return items.size(); }
        private String title(JsonObject item) { return "members".equals(renderedSection) ? first(item, "nickname", "account") : first(item, "name", "title"); }
        private String subtitle(JsonObject item) {
            if ("members".equals(renderedSection)) return roleName(Jsons.string(item, "role")) + " · UID " + Jsons.string(item, "uid");
            if ("files".equals(renderedSection)) {
                if (bool(item, "is_folder")) return Jsons.longValue(item, "child_count") + " 项内容 · 创建者 " + first(item, "nickname", "account");
                return fileType(Jsons.string(item, "mime_type")) + " · " + sizeText(Jsons.longValue(item, "size_bytes"))
                    + " · 上传者 " + first(item, "nickname", "account") + " · 下载 " + Jsons.longValue(item, "download_count") + " 次";
            }
            if ("albums".equals(renderedSection)) return Jsons.longValue(item, "photo_count") + " 项媒体 · " + Jsons.string(item, "description");
            if ("votes".equals(renderedSection)) return Jsons.string(item, "status") + (bool(item, "voted") ? " · 已投票" : " · 未投票");
            return Jsons.longValue(item, "entry_count") + " 人参与 · " + Jsons.string(item, "status");
        }
        final class Holder extends RecyclerView.ViewHolder { final ItemRecordBinding binding; Holder(ItemRecordBinding b) { super(b.getRoot()); binding = b; } }
    }
    private String sectionName() { return "members".equals(section) ? memberLabel() : ("files".equals(section) ? fileRootName() : ("albums".equals(section) ? spacePrefix() + "相册" : ("votes".equals(section) ? spacePrefix() + "投票" : spacePrefix() + "接龙"))); }
    private static String fileType(String mime) {
        if ("inode/directory".equals(mime)) return "文件夹";
        if (mime.startsWith("image/")) return "图片";
        if (mime.startsWith("video/")) return "视频";
        if (mime.startsWith("audio/")) return "音频";
        if (mime.contains("pdf")) return "PDF";
        if (mime.contains("zip") || mime.contains("7z") || mime.contains("rar")) return "压缩文件";
        return "文件";
    }
    private static String sizeText(long size) {
        if (size < 1024) return size + " B";
        if (size < 1024 * 1024) return String.format(java.util.Locale.CHINA, "%.1f KB", size / 1024d);
        if (size < 1024L * 1024L * 1024L) return String.format(java.util.Locale.CHINA, "%.1f MB", size / (1024d * 1024d));
        return String.format(java.util.Locale.CHINA, "%.2f GB", size / (1024d * 1024d * 1024d));
    }
    private static String value(JsonObject item, String... keys) { for (String key : keys) { String value = Jsons.string(item, key); if (!value.isEmpty()) return value; } return ""; }
    private static String first(JsonObject item, String... keys) { for (String key : keys) { String value = Jsons.string(item, key); if (!value.isEmpty()) return value; } return "未命名"; }
    private static boolean bool(JsonObject item, String key) {
        if (item == null || !item.has(key) || item.get(key).isJsonNull()) return false;
        try {
            String value = item.get(key).getAsString().trim().toLowerCase(java.util.Locale.ROOT);
            return "true".equals(value) || "1".equals(value) || "yes".equals(value) || "on".equals(value);
        } catch (RuntimeException ignored) {
            return false;
        }
    }
}
