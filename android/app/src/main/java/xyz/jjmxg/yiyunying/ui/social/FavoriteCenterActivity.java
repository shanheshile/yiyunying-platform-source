package xyz.jjmxg.yiyunying.ui.social;

import android.content.Context;
import android.content.Intent;
import android.os.Bundle;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.view.inputmethod.EditorInfo;

import androidx.annotation.NonNull;
import androidx.activity.OnBackPressedCallback;
import androidx.activity.result.ActivityResult;
import androidx.activity.result.ActivityResultLauncher;
import androidx.activity.result.contract.ActivityResultContracts;
import androidx.recyclerview.widget.LinearLayoutManager;
import androidx.recyclerview.widget.RecyclerView;

import com.google.android.material.chip.Chip;
import com.google.android.material.card.MaterialCardView;
import com.google.android.material.snackbar.Snackbar;
import com.google.gson.JsonArray;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;
import com.google.gson.JsonParser;

import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.core.AppAccess;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.data.api.RequestHandle;
import xyz.jjmxg.yiyunying.databinding.ActivityFavoriteCenterBinding;
import xyz.jjmxg.yiyunying.databinding.ItemSocialDirectoryBinding;
import xyz.jjmxg.yiyunying.ui.chat.ChatActivity;
import xyz.jjmxg.yiyunying.ui.common.ImageLoader;
import xyz.jjmxg.yiyunying.ui.common.RecordDetailDialog;
import xyz.jjmxg.yiyunying.ui.common.YiyunyingDialogBuilder;
import xyz.jjmxg.yiyunying.ui.document.DocumentEditorActivity;
import xyz.jjmxg.yiyunying.ui.forum.ForumPostActivity;
import xyz.jjmxg.yiyunying.ui.main.MainActivity;
import xyz.jjmxg.yiyunying.ui.resource.ResourceHallActivity;
import xyz.jjmxg.yiyunying.ui.moment.MomentTimelineActivity;
import xyz.jjmxg.yiyunying.ui.upload.FilePreviewActivity;

public final class FavoriteCenterActivity extends xyz.jjmxg.yiyunying.ui.common.SystemInsetActivity {
    public static final String EXTRA_PICK_MODE = "favorite_pick_mode";
    public static final String EXTRA_SELECTED_ITEM = "favorite_selected_item";
    public static final String EXTRA_SELECTED_ITEMS = "favorite_selected_items";
    public static final String EXTRA_PICK_MAX = "favorite_pick_max";
    private ActivityFavoriteCenterBinding binding;
    private final List<JsonObject> items = new ArrayList<>();
    private FavoriteAdapter adapter;
    private RequestHandle request;
    private RequestHandle actionRequest;
    private String category = "all";
    private boolean pickMode;
    private boolean selectionMode;
    private int pickMax = 10;
    private final LinkedHashMap<String, JsonObject> selectedItems = new LinkedHashMap<>();

    private static final String STATE_SELECTED_ITEMS = "favorite_selected_states";
    private static final String STATE_SELECTION_MODE = "favorite_selection_mode";

    private final ActivityResultLauncher<Intent> friendRecipientPicker = registerForActivityResult(
        new ActivityResultContracts.StartActivityForResult(), result -> handleRecipients(result, false));
    private final ActivityResultLauncher<Intent> groupRecipientPicker = registerForActivityResult(
        new ActivityResultContracts.StartActivityForResult(), result -> handleRecipients(result, true));
    private final ActivityResultLauncher<Intent> chatroomRecipientPicker = registerForActivityResult(
        new ActivityResultContracts.StartActivityForResult(), result -> handleRecipients(result, true));

    public static void open(Context context) {
        context.startActivity(new Intent(context, FavoriteCenterActivity.class));
    }

    public static Intent pickerIntent(Context context) {
        return pickerIntent(context, 10);
    }

    public static Intent pickerIntent(Context context, int maxCount) {
        return new Intent(context, FavoriteCenterActivity.class)
            .putExtra(EXTRA_PICK_MODE, true)
            .putExtra(EXTRA_PICK_MAX, Math.max(1, maxCount));
    }

    @Override protected void onCreate(Bundle state) {
        super.onCreate(state);
        binding = ActivityFavoriteCenterBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());
        getOnBackPressedDispatcher().addCallback(this, new OnBackPressedCallback(true) {
            @Override public void handleOnBackPressed() {
                exitSelectionOrFinish();
            }
        });
        pickMode = getIntent().getBooleanExtra(EXTRA_PICK_MODE, false);
        pickMax = Math.max(1, getIntent().getIntExtra(EXTRA_PICK_MAX, 10));
        selectionMode = !pickMode && state != null && state.getBoolean(STATE_SELECTION_MODE, false);
        if (state != null) restoreSelection(state.getString(STATE_SELECTED_ITEMS, ""));
        if (pickMode) binding.toolbar.setTitle("选择要发送的收藏");
        else if (selectionMode) binding.toolbar.setTitle("批量发送收藏");
        binding.sendSelectedFavorite.setOnClickListener(view -> {
            if (pickMode) finishWithSelection(); else chooseShareDestination();
        });
        binding.toolbar.setNavigationOnClickListener(view -> exitSelectionOrFinish());
        adapter = new FavoriteAdapter();
        binding.recycler.setLayoutManager(new LinearLayoutManager(this));
        binding.recycler.setAdapter(adapter);
        xyz.jjmxg.yiyunying.ui.common.TopCenterDoubleTap.attach(
            binding.toolbar, binding.recycler);
        binding.swipeRefresh.setOnRefreshListener(this::load);
        binding.searchLayout.setEndIconOnClickListener(view -> load());
        binding.searchInput.setOnEditorActionListener((view, action, event) -> {
            if (action == EditorInfo.IME_ACTION_SEARCH) { load(); return true; }
            return false;
        });
        renderSelection();
        load();
    }

    private void exitSelectionOrFinish() {
        if (selectionMode) {
            exitSelectionMode();
            return;
        }
        finish();
    }

    private boolean isSelecting() {
        return pickMode || selectionMode;
    }

    private void enterSelectionMode(JsonObject first) {
        if (pickMode) return;
        selectionMode = true;
        binding.toolbar.setTitle("批量发送收藏");
        selectItem(first);
    }

    private void exitSelectionMode() {
        selectionMode = false;
        selectedItems.clear();
        if (binding != null) {
            binding.toolbar.setTitle("我的收藏");
            renderSelection();
        }
        if (adapter != null) adapter.notifyDataSetChanged();
    }

    private void load() {
        if (request != null) request.cancel();
        binding.progress.setVisibility(View.VISIBLE);
        Map<String, String> query = new LinkedHashMap<>();
        query.put("category", category);
        String keyword = binding.searchInput.getText() == null ? "" : binding.searchInput.getText().toString().trim();
        if (!keyword.isEmpty()) query.put("keyword", keyword);
        AppAccess.from(this).repository().getCached("/api/user/favorites", query, cached -> {
            if (binding == null || isFinishing() || isDestroyed() || !cached.isSuccessful()) return;
            renderCategories(Jsons.array(cached.dataObject(), "categories"));
            items.clear();
            items.addAll(cached.objectItems());
            adapter.notifyDataSetChanged();
            binding.emptyText.setVisibility(items.isEmpty() ? View.VISIBLE : View.GONE);
            binding.progress.setVisibility(View.INVISIBLE);
        });
        request = AppAccess.from(this).repository().get("/api/user/favorites", query, result -> {
            request = null;
            if (binding == null) return;
            binding.progress.setVisibility(View.INVISIBLE);
            binding.swipeRefresh.setRefreshing(false);
            if (!result.isSuccessful()) {
                binding.emptyText.setText(result.message().isEmpty() ? "收藏加载失败，请下拉重试" : result.message());
                binding.emptyText.setVisibility(View.VISIBLE);
                return;
            }
            renderCategories(Jsons.array(result.dataObject(), "categories"));
            items.clear();
            items.addAll(result.objectItems());
            adapter.notifyDataSetChanged();
            binding.emptyText.setText("当前分类还没有收藏内容");
            binding.emptyText.setVisibility(items.isEmpty() ? View.VISIBLE : View.GONE);
        });
    }

    private void renderCategories(JsonArray categories) {
        binding.categoryChips.removeAllViews();
        for (JsonElement element : categories) {
            if (!element.isJsonObject()) continue;
            JsonObject item = element.getAsJsonObject();
            String code = Jsons.string(item, "code");
            Chip chip = new Chip(this);
            chip.setText(Jsons.string(item, "name") + " " + Jsons.longValue(item, "count"));
            chip.setCheckable(true);
            chip.setChecked(category.equals(code));
            chip.setOnClickListener(view -> {
                if (category.equals(code)) return;
                category = code;
                load();
            });
            binding.categoryChips.addView(chip);
        }
    }

    private void openItem(JsonObject item) {
        if (pickMode) {
            boolean selected = selectedItems.containsKey(favoriteKey(item));
            RecordDetailDialog.showChoice(this, "收藏快照", displayItem(item),
                "关闭", null,
                selected ? "取消选择" : "选择此收藏", () -> selectItem(item));
            return;
        }
        showSnapshot(item);
    }

    private void showSnapshot(JsonObject item) {
        RecordDetailDialog.show(this, "收藏详情", displayItem(item),
            sourceAction(item), () -> openSource(item));
    }

    /** Builds a user-facing snapshot instead of exposing database and transport fields. */
    public static JsonObject displayItem(JsonObject item) {
        JsonObject snapshot = Jsons.object(item, "snapshot");
        JsonObject display = new JsonObject();
        String type = firstText(snapshot, item, "favorite_type", "content_type", "media_type", "type");
        display.addProperty("收藏类型", favoriteTypeLabel(type));

        String title = firstText(snapshot, item,
            "标题", "名称", "文件名", "title", "name", "file_name", "original_name", "scope_name");
        if (!title.isEmpty()) display.addProperty("标题", title);
        String author = firstText(snapshot, item,
            "发送人", "作者", "发布者", "上传者", "sender_name", "author_name", "user_name", "nickname", "display_name");
        if (!author.isEmpty()) display.addProperty("发送人", author);
        String source = firstText(snapshot, item,
            "来自", "来源", "source_name", "scope_name", "conversation_name", "room_name");
        if (!source.isEmpty() && !source.equals(title)) display.addProperty("来自", source);

        String content = firstText(snapshot, item,
            "内容", "动态内容", "正文摘要", "说明", "简介", "content", "text", "summary", "description", "excerpt");
        if (!content.isEmpty()) display.addProperty("内容", content);
        else display.addProperty("内容", "已保存内容快照，可点击下方按钮回到原位置查看完整内容");

        String created = firstText(snapshot, item,
            "发送时间", "发布时间", "创建时间", "修改时间", "created_at", "sent_at", "published_at", "updated_at");
        if (!created.isEmpty()) display.addProperty("原内容时间", created);
        String favorited = firstText(snapshot, item, "收藏时间", "favorited_at", "favorite_time");
        if (!favorited.isEmpty()) display.addProperty("收藏时间", favorited);

        JsonArray attachments = firstArray(snapshot, item, "attachments", "media_attachments", "files", "media");
        if (attachments.isEmpty()) {
            JsonObject directAttachment = directAttachment(snapshot, item, type);
            if (directAttachment != null) attachments.add(directAttachment);
        }
        if (!attachments.isEmpty()) display.add("attachments", attachments.deepCopy());
        return display;
    }

    /** Converts legacy single-file favorites into the same media model used by new snapshots. */
    private static JsonObject directAttachment(JsonObject snapshot, JsonObject item, String favoriteType) {
        String url = firstText(snapshot, item,
            "preview_url", "thumbnail_url", "file_url", "image_url", "media_url", "download_url", "url");
        if (url.isEmpty()) return null;
        String mediaType = firstText(snapshot, item, "media_type", "content_type");
        if (mediaType.isEmpty() || "message".equals(mediaType)) mediaType = inferMediaType(favoriteType, url);
        JsonObject attachment = new JsonObject();
        attachment.addProperty("media_type", mediaType);
        attachment.addProperty("url", url);
        String thumbnail = firstText(snapshot, item, "thumbnail_url", "preview_url");
        if (!thumbnail.isEmpty()) attachment.addProperty("thumbnail_url", thumbnail);
        String name = firstText(snapshot, item,
            "文件名", "标题", "file_name", "original_name", "name", "title");
        if (!name.isEmpty()) attachment.addProperty("file_name", name);
        copyLong(snapshot, item, attachment, "size_bytes", "file_size", "size");
        copyLong(snapshot, item, attachment, "width");
        copyLong(snapshot, item, attachment, "height");
        copyLong(snapshot, item, attachment, "duration_ms", "duration");
        String mime = firstText(snapshot, item, "mime_type", "mime");
        if (!mime.isEmpty()) attachment.addProperty("mime_type", mime);
        return attachment;
    }

    private static void copyLong(JsonObject primary, JsonObject fallback, JsonObject target,
                                 String outputKey, String... sourceKeys) {
        long value = Jsons.longValue(primary, outputKey);
        if (value <= 0) value = Jsons.longValue(fallback, outputKey);
        for (String key : sourceKeys) {
            if (value > 0) break;
            value = Jsons.longValue(primary, key);
            if (value <= 0) value = Jsons.longValue(fallback, key);
        }
        if (value > 0) target.addProperty(outputKey, value);
    }

    private static String inferMediaType(String favoriteType, String url) {
        String normalizedType = favoriteType == null ? "" : favoriteType.trim().toLowerCase(java.util.Locale.ROOT);
        if ("image".equals(normalizedType) || "gif".equals(normalizedType)
            || "video".equals(normalizedType) || "audio".equals(normalizedType)
            || "voice".equals(normalizedType) || "sticker".equals(normalizedType)) return normalizedType;
        String clean = url == null ? "" : url.toLowerCase(java.util.Locale.ROOT).split("\\?", 2)[0];
        if (clean.endsWith(".gif")) return "gif";
        if (clean.endsWith(".jpg") || clean.endsWith(".jpeg") || clean.endsWith(".png")
            || clean.endsWith(".webp") || clean.endsWith(".bmp") || clean.endsWith(".dng")) return "image";
        if (clean.endsWith(".mp4") || clean.endsWith(".mov") || clean.endsWith(".mkv")
            || clean.endsWith(".webm") || clean.endsWith(".avi")) return "video";
        if (clean.endsWith(".mp3") || clean.endsWith(".wav") || clean.endsWith(".m4a")
            || clean.endsWith(".aac") || clean.endsWith(".ogg") || clean.endsWith(".flac")) return "audio";
        return "file";
    }

    private static String firstText(JsonObject primary, JsonObject fallback, String... keys) {
        for (String key : keys) {
            String value = Jsons.string(primary, key);
            if (!value.trim().isEmpty()) return value.trim();
            value = Jsons.string(fallback, key);
            if (!value.trim().isEmpty()) return value.trim();
        }
        return "";
    }

    private static JsonArray firstArray(JsonObject primary, JsonObject fallback, String... keys) {
        for (String key : keys) {
            JsonArray value = arrayValue(primary, key);
            if (!value.isEmpty()) return value;
            value = arrayValue(fallback, key);
            if (!value.isEmpty()) return value;
        }
        return new JsonArray();
    }

    private static JsonArray arrayValue(JsonObject object, String key) {
        if (object == null || !object.has(key) || object.get(key).isJsonNull()) return new JsonArray();
        JsonElement value = object.get(key);
        if (value.isJsonArray()) return value.getAsJsonArray();
        if (value.isJsonPrimitive() && value.getAsJsonPrimitive().isString()) {
            try {
                JsonElement parsed = JsonParser.parseString(value.getAsString());
                if (parsed.isJsonArray()) return parsed.getAsJsonArray();
            } catch (RuntimeException ignored) { }
        }
        return new JsonArray();
    }

    private static String favoriteTypeLabel(String type) {
        if (type == null) return "收藏内容";
        switch (type.trim().toLowerCase(java.util.Locale.ROOT)) {
            case "message": return "聊天记录";
            case "post": return "论坛帖子";
            case "moment": return "生活动态";
            case "note": return "笔记";
            case "bounty": return "悬赏";
            case "resource": return "资源";
            case "app": return "应用";
            case "goods": return "商品";
            case "upload": case "file": return "文件";
            case "image": case "gif": return "图片";
            case "video": return "视频";
            case "audio": case "voice": return "音频";
            case "sticker": return "表情包";
            case "link": return "链接";
            default: return "收藏内容";
        }
    }

    private String sourceAction(JsonObject item) {
        String action = Jsons.string(item, "source_action");
        if (!action.isEmpty()) return action;
        String type = Jsons.string(item, "favorite_type");
        if ("message".equals(type)) return "回到聊天位置";
        if ("post".equals(type)) return "打开帖子";
        if ("moment".equals(type)) return "打开动态";
        if ("note".equals(type)) return "打开笔记";
        if ("bounty".equals(type)) return "打开悬赏";
        if ("resource".equals(type)) return "打开资源";
        if ("app".equals(type)) return "打开应用";
        if ("goods".equals(type)) return "打开商品";
        if ("upload".equals(type)) return "打开文件";
        return "打开来源";
    }

    private void openSource(JsonObject item) {
        if (pickMode) {
            boolean selected = selectedItems.containsKey(favoriteKey(item));
            RecordDetailDialog.showChoice(this, "收藏快照", displayItem(item),
                "关闭", null,
                selected ? "取消选择" : "选择此收藏", () -> selectItem(item));
            return;
        }
        String type = Jsons.string(item, "favorite_type");
        if ("post".equals(type)) {
            ForumPostActivity.open(this, Jsons.longValue(item, "target_id"));
            return;
        }
        if ("moment".equals(type)) {
            MomentTimelineActivity.openMoment(
                this,
                Jsons.longValue(item, "target_id"),
                Jsons.longValue(item, "user_id"),
                Jsons.string(item, "title"));
            return;
        }
        if ("note".equals(type)) {
            long documentId = Jsons.longValue(item, "document_id");
            if (documentId <= 0) documentId = Jsons.longValue(item, "target_id");
            startActivity(new Intent(this, DocumentEditorActivity.class)
                .putExtra(DocumentEditorActivity.EXTRA_DOCUMENT_ID, documentId));
            return;
        }
        if ("bounty".equals(type)) {
            startActivity(MainActivity.moduleIntent(this, "bounties", Jsons.longValue(item, "target_id")));
            return;
        }
        if ("resource".equals(type)) {
            ResourceHallActivity.openResource(this, Jsons.longValue(item, "target_id"));
            return;
        }
        if ("app".equals(type)) {
            ResourceHallActivity.openApp(this, Jsons.longValue(item, "target_id"));
            return;
        }
        if ("goods".equals(type)) {
            startActivity(MainActivity.moduleIntent(this, "shop_goods", Jsons.longValue(item, "target_id")));
            return;
        }
        if ("upload".equals(type)) {
            JsonObject file = item.deepCopy();
            file.addProperty("id", Jsons.longValue(item, "target_id"));
            file.addProperty("original_name", Jsons.string(item, "title"));
            file.addProperty("file_url", Jsons.string(item, "preview_url"));
            FilePreviewActivity.open(this, file);
            return;
        }
        if ("message".equals(type)) {
            String scope = Jsons.string(item, "scope_type");
            long scopeId = Jsons.longValue(item, "scope_id");
            Intent intent;
            if ("group".equals(scope)) {
                intent = ChatActivity.roomIntent(this, scopeId, Jsons.string(item, "scope_name"));
            } else if ("service".equals(scope)) {
                intent = ChatActivity.userServiceIntent(this);
            } else {
                intent = ChatActivity.conversationIntent(
                    this,
                    scopeId,
                    Jsons.longValue(item, "peer_user_id"),
                    favoriteChatTitle(item));
            }
            long messageId = Jsons.longValue(item, "message_id");
            if (messageId <= 0) messageId = Jsons.longValue(item, "target_id");
            if (messageId > 0) ChatActivity.scrollToMessage(intent, messageId);
            startActivity(intent);
            return;
        }
        RecordDetailDialog.show(this, "收藏详情", displayItem(item));
    }

    private String favoriteChatTitle(JsonObject item) {
        String[] keys = {"peer_name", "scope_name", "peer_account", "title"};
        for (String key : keys) {
            String value = Jsons.string(item, key).trim();
            if (!value.isEmpty() && !"私聊".equals(value)) return value;
        }
        return "好友";
    }

    private void selectItem(JsonObject item) {
        if (item == null) return;
        String key = favoriteKey(item);
        if (selectedItems.containsKey(key)) {
            selectedItems.remove(key);
        } else {
            if (selectedItems.size() >= pickMax) {
                Snackbar.make(binding.getRoot(), "一次最多选择 " + pickMax + " 项收藏", Snackbar.LENGTH_SHORT).show();
                return;
            }
            selectedItems.put(key, item.deepCopy());
        }
        renderSelection();
        adapter.notifyDataSetChanged();
    }

    private void renderSelection() {
        if (binding == null) return;
        boolean active = isSelecting();
        binding.selectionBar.setVisibility(active ? View.VISIBLE : View.GONE);
        if (!active) return;
        int count = selectedItems.size();
        if (count == 0) {
            binding.selectedFavoriteText.setText(
                pickMode ? "尚未选择；短按选择，长按预览详情" : "短按继续选择，长按预览详情");
        } else {
            StringBuilder summary = new StringBuilder("已选 ").append(count).append('/').append(pickMax).append(" 项");
            int shown = 0;
            for (JsonObject item : selectedItems.values()) {
                String title = Jsons.string(item, "title");
                if (title.isEmpty()) continue;
                summary.append(shown == 0 ? "：" : "、").append(title);
                if (++shown == 2) break;
            }
            if (count > shown) summary.append(" 等");
            binding.selectedFavoriteText.setText(summary.toString());
        }
        String action = pickMode ? "发送" : "转发";
        binding.sendSelectedFavorite.setText(count == 0 ? action : action + " (" + count + ")");
        binding.sendSelectedFavorite.setEnabled(count > 0 && actionRequest == null);
    }

    private void chooseShareDestination() {
        if (selectedItems.isEmpty() || actionRequest != null) return;
        new YiyunyingDialogBuilder(this)
            .setTitle("发送收藏")
            .setItems(new String[]{"发送给好友", "发送到群聊", "发送到聊天室"}, (dialog, which) -> {
                if (which == 0) {
                    friendRecipientPicker.launch(SocialDirectoryActivity.pickFriendsIntent(
                        this, 10, "选择接收收藏的好友", new long[0], "该好友不可选择"));
                } else if (which == 1) {
                    groupRecipientPicker.launch(SocialDirectoryActivity.pickGroupsIntent(
                        this, 10, "选择接收收藏的群聊", new long[0]));
                } else {
                    chatroomRecipientPicker.launch(SocialDirectoryActivity.pickChatroomsIntent(
                        this, 10, "选择接收收藏的聊天室", new long[0]));
                }
            })
            .setNegativeButton("取消", null)
            .show();
    }

    private void handleRecipients(ActivityResult result, boolean rooms) {
        if (result.getResultCode() != RESULT_OK || result.getData() == null || selectedItems.isEmpty()) return;
        String raw = result.getData().getStringExtra(SocialDirectoryActivity.EXTRA_SELECTED_ITEMS);
        if (raw == null || raw.trim().isEmpty()) return;
        ArrayList<JsonObject> recipients = new ArrayList<>();
        try {
            JsonElement parsed = JsonParser.parseString(raw);
            if (parsed.isJsonArray()) {
                for (JsonElement element : parsed.getAsJsonArray()) {
                    if (!element.isJsonObject()) continue;
                    JsonObject recipient = element.getAsJsonObject();
                    long id = Jsons.longValue(recipient, rooms ? "id" : "user_id");
                    if (id > 0L) recipients.add(recipient.deepCopy());
                }
            }
        } catch (RuntimeException ignored) { }
        if (recipients.isEmpty()) {
            Snackbar.make(binding.getRoot(), "没有选择有效的接收对象", Snackbar.LENGTH_SHORT).show();
            return;
        }
        sendFavorites(recipients, rooms, 0, 0, 0);
    }

    private void sendFavorites(
        List<JsonObject> recipients,
        boolean rooms,
        int index,
        int successCount,
        int failedCount
    ) {
        if (isFinishing() || isDestroyed() || binding == null) return;
        if (index >= recipients.size()) {
            actionRequest = null;
            binding.progress.setVisibility(View.INVISIBLE);
            renderSelection();
            String result = failedCount == 0
                ? "已发送给 " + successCount + " 个接收对象"
                : "发送完成：成功 " + successCount + " 个，失败 " + failedCount + " 个";
            Snackbar.make(binding.getRoot(), result, Snackbar.LENGTH_LONG).show();
            if (failedCount == 0) exitSelectionMode();
            return;
        }
        JsonObject recipient = recipients.get(index);
        long recipientId = Jsons.longValue(recipient, rooms ? "id" : "user_id");
        if (recipientId <= 0L) {
            sendFavorites(recipients, rooms, index + 1, successCount, failedCount + 1);
            return;
        }
        JsonObject body = favoriteMessageBody();
        String path;
        if (rooms) {
            path = "/api/user/chat-rooms/" + recipientId + "/messages";
        } else {
            path = "/api/user/messages/private";
            body.addProperty("to_user_id", recipientId);
        }
        binding.progress.setVisibility(View.VISIBLE);
        renderSelection();
        actionRequest = AppAccess.from(this).repository().post(path, body, result -> {
            actionRequest = null;
            if (isFinishing() || isDestroyed() || binding == null) return;
            sendFavorites(
                recipients,
                rooms,
                index + 1,
                successCount + (result.isSuccessful() ? 1 : 0),
                failedCount + (result.isSuccessful() ? 0 : 1)
            );
        });
    }

    private JsonObject favoriteMessageBody() {
        JsonArray attachments = new JsonArray();
        for (JsonObject item : selectedItems.values()) {
            JsonObject attachment = new JsonObject();
            attachment.addProperty("media_type", "favorite");
            String preview = Jsons.string(item, "preview_url");
            attachment.addProperty("url", preview.isEmpty() ? "/api/user/me" : preview);
            attachment.addProperty("file_name", Jsons.string(item, "title"));
            attachment.add("metadata", item.deepCopy());
            attachments.add(attachment);
        }
        JsonArray tags = new JsonArray();
        tags.add("收藏");
        JsonObject body = new JsonObject();
        body.addProperty("content", "");
        body.add("attachments", attachments);
        body.add("tags", tags);
        return body;
    }

    private void finishWithSelection() {
        if (selectedItems.isEmpty()) return;
        JsonArray selected = selectedItemsArray();
        JsonObject first = selectedItems.values().iterator().next();
        setResult(RESULT_OK, new Intent()
            .putExtra(EXTRA_SELECTED_ITEMS, selected.toString())
            .putExtra(EXTRA_SELECTED_ITEM, first.toString()));
        finish();
    }

    private JsonArray selectedItemsArray() {
        JsonArray array = new JsonArray();
        for (JsonObject item : selectedItems.values()) array.add(item.deepCopy());
        return array;
    }

    private void restoreSelection(String raw) {
        if (raw == null || raw.trim().isEmpty()) return;
        try {
            JsonElement parsed = Jsons.parse(raw);
            if (!parsed.isJsonArray()) return;
            for (JsonElement element : parsed.getAsJsonArray()) {
                if (!element.isJsonObject() || selectedItems.size() >= pickMax) continue;
                JsonObject item = element.getAsJsonObject();
                selectedItems.put(favoriteKey(item), item.deepCopy());
            }
        } catch (RuntimeException ignored) { }
    }

    private static String favoriteKey(JsonObject item) {
        String type = Jsons.string(item, "favorite_type");
        long targetId = Jsons.longValue(item, "target_id");
        if (targetId <= 0) targetId = Jsons.longValue(item, "id");
        return type + ":" + targetId
            + ":" + Jsons.string(item, "scope_type") + ":" + Jsons.longValue(item, "scope_id");
    }

    @Override protected void onSaveInstanceState(@NonNull Bundle state) {
        if (!selectedItems.isEmpty()) state.putString(STATE_SELECTED_ITEMS, selectedItemsArray().toString());
        state.putBoolean(STATE_SELECTION_MODE, selectionMode);
        super.onSaveInstanceState(state);
    }

    @Override protected void onDestroy() {
        if (request != null) request.cancel();
        if (actionRequest != null) actionRequest.cancel();
        binding = null;
        super.onDestroy();
    }

    private final class FavoriteAdapter extends RecyclerView.Adapter<FavoriteAdapter.Holder> {
        @NonNull @Override public Holder onCreateViewHolder(@NonNull ViewGroup parent, int viewType) {
            return new Holder(ItemSocialDirectoryBinding.inflate(LayoutInflater.from(parent.getContext()), parent, false));
        }

        @Override public void onBindViewHolder(@NonNull Holder holder, int position) {
            JsonObject item = items.get(position);
            holder.binding.title.setText(Jsons.string(item, "title"));
            holder.binding.subtitle.setText(Jsons.string(item, "summary"));
            holder.binding.metadata.setText("收藏于 " + Jsons.string(item, "favorited_at"));
            String preview = ImageLoader.get().absoluteUrl(FavoriteCenterActivity.this, Jsons.string(item, "preview_url"));
            ImageLoader.get().load(preview, holder.binding.avatar, icon(item));
            holder.binding.moreButton.setImageResource(R.drawable.ic_chevron_right);
            boolean selected = isSelecting() && selectedItems.containsKey(favoriteKey(item));
            if (holder.binding.getRoot() instanceof MaterialCardView) {
                MaterialCardView card = (MaterialCardView) holder.binding.getRoot();
                card.setStrokeWidth(selected ? Math.round(getResources().getDisplayMetrics().density * 2f) : 0);
                card.setStrokeColor(xyz.jjmxg.yiyunying.ui.common.ThemeColors.primary(FavoriteCenterActivity.this));
            }
            holder.binding.selectionCheck.setVisibility(isSelecting() ? View.VISIBLE : View.GONE);
            holder.binding.selectionCheck.setChecked(selected);
            holder.binding.moreButton.setAlpha(0.78f);
            holder.binding.moreButton.setContentDescription(selectionMode ? "查看收藏快照" : "打开收藏来源");
            holder.binding.moreButton.setOnClickListener(view -> {
                if (pickMode) openItem(item);
                else if (selectionMode) showSnapshot(item);
                else openSource(item);
            });
            holder.binding.getRoot().setOnClickListener(view -> {
                if (isSelecting()) selectItem(item); else openItem(item);
            });
            holder.binding.getRoot().setOnLongClickListener(view -> {
                if (pickMode || selectionMode) openItem(item);
                else enterSelectionMode(item);
                return true;
            });
        }

        private int icon(JsonObject item) {
            String type = Jsons.string(item, "favorite_type");
            if ("message".equals(type)) return R.drawable.ic_chat;
            if ("post".equals(type)) return R.drawable.ic_forum;
            if ("moment".equals(type)) return R.drawable.ic_content;
            if ("note".equals(type)) return R.drawable.ic_document;
            if ("bounty".equals(type)) return R.drawable.ic_wallet;
            if ("resource".equals(type)) return R.drawable.ic_file;
            if ("app".equals(type)) return R.drawable.ic_apps;
            if ("goods".equals(type)) return R.drawable.ic_wallet;
            if ("upload".equals(type)) return R.drawable.ic_file;
            return R.drawable.ic_content;
        }

        @Override public int getItemCount() { return items.size(); }
        final class Holder extends RecyclerView.ViewHolder {
            final ItemSocialDirectoryBinding binding;
            Holder(ItemSocialDirectoryBinding binding) { super(binding.getRoot()); this.binding = binding; }
        }
    }
}
