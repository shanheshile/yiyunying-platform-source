package xyz.jjmxg.yiyunying.ui.chat;

import android.content.ClipData;
import android.content.ClipboardManager;
import android.content.Context;
import android.content.Intent;
import android.os.Bundle;
import android.text.Editable;
import android.text.TextWatcher;
import android.view.MenuItem;
import android.view.View;

import androidx.recyclerview.widget.LinearLayoutManager;

import xyz.jjmxg.yiyunying.ui.common.YiyunyingDialogBuilder;
import com.google.android.material.snackbar.Snackbar;
import com.google.gson.JsonArray;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;
import com.google.gson.JsonParser;

import java.util.ArrayList;
import java.util.List;
import java.util.Locale;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.core.AppAccess;
import xyz.jjmxg.yiyunying.core.RuntimeLanguage;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.data.api.RequestHandle;
import xyz.jjmxg.yiyunying.databinding.ActivityForwardSnapshotBinding;
import xyz.jjmxg.yiyunying.domain.Role;
import xyz.jjmxg.yiyunying.ui.common.SecureMediaClipboard;

public final class ForwardSnapshotActivity extends xyz.jjmxg.yiyunying.ui.common.SystemInsetActivity {
    private static final String EXTRA_FORWARD_ID = "forward_id";
    private static final String EXTRA_APP_ID = "app_id";
    private static final String EXTRA_EMBEDDED = "embedded_forward";
    private ActivityForwardSnapshotBinding binding;
    private ChatAdapter adapter;
    private RequestHandle request;
    private final List<JsonObject> source = new ArrayList<>();

    public static void open(Context context, long forwardId) {
        open(context, forwardId, 0);
    }

    public static void open(Context context, long forwardId, long appId) {
        if (forwardId <= 0) return;
        context.startActivity(new Intent(context, ForwardSnapshotActivity.class)
            .putExtra(EXTRA_FORWARD_ID, forwardId).putExtra(EXTRA_APP_ID, Math.max(0, appId)));
    }

    public static void openEmbedded(Context context, JsonObject forward, long appId) {
        if (forward == null || Jsons.array(forward, "items").isEmpty()) return;
        context.startActivity(new Intent(context, ForwardSnapshotActivity.class)
            .putExtra(EXTRA_EMBEDDED, forward.toString())
            .putExtra(EXTRA_APP_ID, Math.max(0, appId)));
    }

    @Override protected void onCreate(Bundle state) {
        super.onCreate(state);
        binding = ActivityForwardSnapshotBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());
        binding.toolbar.setTitle("合并转发的聊天记录");
        binding.toolbar.setSubtitle("只读快照，可搜索和复制");
        binding.toolbar.setNavigationOnClickListener(view -> finish());
        MenuItem copy = binding.toolbar.getMenu().add("复制全部文字");
        copy.setShowAsAction(MenuItem.SHOW_AS_ACTION_NEVER);
        MenuItem copyMedia = binding.toolbar.getMenu().add("复制全部媒体文件");
        copyMedia.setShowAsAction(MenuItem.SHOW_AS_ACTION_NEVER);
        binding.toolbar.setOnMenuItemClickListener(item -> {
            if (item == copyMedia) copyAllMedia(); else copyAll();
            return true;
        });
        adapter = new ChatAdapter(0, Role.USER, new ChatAdapter.Listener() {
            @Override public void onLongPress(JsonObject message) { copyMessage(message); }
        });
        adapter.setManagedAppId(getIntent().getLongExtra(EXTRA_APP_ID, 0));
        binding.recycler.setLayoutManager(new LinearLayoutManager(this));
        binding.recycler.setAdapter(adapter);
        binding.searchInput.addTextChangedListener(new TextWatcher() {
            @Override public void beforeTextChanged(CharSequence value, int start, int count, int after) { }
            @Override public void onTextChanged(CharSequence value, int start, int before, int count) { filter(value == null ? "" : value.toString()); }
            @Override public void afterTextChanged(Editable value) { }
        });
        binding.clearSearch.setOnClickListener(view -> binding.searchInput.setText(""));
        load();
    }

    private void load() {
        String embedded = getIntent().getStringExtra(EXTRA_EMBEDDED);
        if (embedded != null && !embedded.trim().isEmpty()) {
            try {
                JsonObject forward = JsonParser.parseString(embedded).getAsJsonObject();
                renderForward(forward);
                binding.progress.setVisibility(View.INVISIBLE);
                return;
            } catch (RuntimeException ignored) {
                binding.empty.setText("内嵌聊天记录快照无法解析");
                binding.empty.setVisibility(View.VISIBLE);
            }
        }
        long id = getIntent().getLongExtra(EXTRA_FORWARD_ID, 0);
        long appId = getIntent().getLongExtra(EXTRA_APP_ID, 0);
        Role role = AppAccess.from(this).session().role();
        String path;
        if (appId > 0 && role == Role.PLATFORM) {
            path = "/api/platform/apps/" + appId + "/message-forwards/" + id;
        } else if (appId > 0 && role == Role.ADMIN) {
            path = "/api/admin/apps/" + appId + "/message-forwards/" + id;
        } else {
            path = "/api/user/message-forwards/" + id;
        }
        binding.progress.setVisibility(View.VISIBLE);
        request = AppAccess.from(this).repository().get(path, new java.util.LinkedHashMap<>(), result -> {
            request = null;
            if (binding == null) return;
            binding.progress.setVisibility(View.INVISIBLE);
            if (!result.isSuccessful()) {
                binding.empty.setText(result.message().isEmpty() ? "聊天记录快照加载失败" : result.message());
                binding.empty.setVisibility(View.VISIBLE);
                return;
            }
            renderForward(Jsons.object(result.dataObject(), "forward"));
        });
    }

    private void renderForward(JsonObject forward) {
        String title = Jsons.string(forward, "title");
        if (!title.isEmpty()) RuntimeLanguage.setDynamicToolbarTitle(binding.toolbar, title);
        String mode = Jsons.string(forward, "anonymity_mode");
        boolean auditView = boolValue(forward, "audit_identity_visible");
        if (auditView) {
            binding.toolbar.setSubtitle("1/2/3 实名审计视图 · 匿名只影响用户之间的展示");
            JsonObject context = Jsons.object(forward, "source_context");
            JsonObject forwarder = Jsons.object(forward, "forwarded_by");
            String forwarderName = Jsons.string(forwarder, "name");
            if (forwarderName.isEmpty()) forwarderName = Jsons.string(forwarder, "account");
            String uid = Jsons.string(forwarder, "uid");
            String sourceName = Jsons.string(context, "display_name");
            StringBuilder audit = new StringBuilder("实名审计信息");
            audit.append("\n转发人：").append(forwarderName.isEmpty() ? "身份未记录" : forwarderName);
            if (!uid.isEmpty()) audit.append("（UID ").append(uid).append('）');
            audit.append("\n原会话：").append(sourceName.isEmpty() ? "来源记录缺失" : sourceName);
            binding.auditSummary.setText(audit);
            binding.auditSummary.setVisibility(View.VISIBLE);
        } else {
            binding.auditSummary.setVisibility(View.GONE);
            if ("full".equals(mode)) binding.toolbar.setSubtitle("全匿名只读快照，已隐藏用户身份和原会话名称");
            else if ("selected".equals(mode)) binding.toolbar.setSubtitle("部分匿名只读快照，已隐藏原会话名称");
            else binding.toolbar.setSubtitle("只读快照，可继续查看内层转发");
        }
        source.clear();
        for (JsonElement element : Jsons.array(forward, "items")) {
            if (element.isJsonObject()) source.add(element.getAsJsonObject());
        }
        filter(binding.searchInput.getText() == null ? "" : binding.searchInput.getText().toString());
    }

    private static boolean boolValue(JsonObject object, String key) {
        try { return object.has(key) && !object.get(key).isJsonNull() && object.get(key).getAsBoolean(); }
        catch (RuntimeException ignored) { return false; }
    }

    private void filter(String query) {
        String needle = query.trim().toLowerCase(Locale.CHINA);
        List<JsonObject> visible = new ArrayList<>();
        for (JsonObject item : source) {
            if (needle.isEmpty() || searchable(item).contains(needle)) visible.add(item);
        }
        adapter.submit(visible);
        binding.empty.setVisibility(visible.isEmpty() ? View.VISIBLE : View.GONE);
        binding.empty.setText(source.isEmpty() ? "这份快照没有可显示的消息" : "没有找到匹配的聊天内容");
        binding.resultCount.setText(needle.isEmpty() ? "共 " + source.size() + " 条，只读展示"
            : "找到 " + visible.size() + " 条，保留原发送者视角");
    }

    private String searchable(JsonObject item) {
        StringBuilder text = new StringBuilder();
        text.append(Jsons.string(item, "sender_name")).append(' ')
            .append(Jsons.string(item, "sender_display_name")).append(' ')
            .append(Jsons.string(item, "sender_badge")).append(' ')
            .append(Jsons.string(item, "sender_role")).append(' ')
            .append(Jsons.string(item, "content"));
        for (JsonElement element : Jsons.array(item, "tags")) {
            if (element.isJsonPrimitive()) text.append(' ').append(element.getAsString());
        }
        for (JsonElement element : Jsons.array(item, "tags_json")) {
            if (element.isJsonPrimitive()) text.append(' ').append(element.getAsString());
        }
        for (JsonElement element : Jsons.array(item, "attachments")) {
            if (!element.isJsonObject()) continue;
            JsonObject attachment = element.getAsJsonObject();
            text.append(' ').append(Jsons.string(attachment, "file_name"))
                .append(' ').append(Jsons.string(attachment, "media_type"))
                .append(' ').append(Jsons.string(attachment, "mime_type"));
        }
        appendForwardSearch(text, Jsons.object(item, "forward_bundle"), 0);
        return text.toString().toLowerCase(Locale.CHINA);
    }

    private void appendForwardSearch(StringBuilder text, JsonObject forward, int depth) {
        if (forward.entrySet().isEmpty() || depth >= 8) return;
        text.append(' ').append(Jsons.string(forward, "title"));
        for (JsonElement element : Jsons.array(forward, "items")) {
            if (!element.isJsonObject()) continue;
            JsonObject nested = element.getAsJsonObject();
            text.append(' ').append(Jsons.string(nested, "sender_name"))
                .append(' ').append(Jsons.string(nested, "content"));
            for (JsonElement attachment : Jsons.array(nested, "attachments")) {
                if (attachment.isJsonObject()) text.append(' ').append(Jsons.string(attachment.getAsJsonObject(), "file_name"));
            }
            appendForwardSearch(text, Jsons.object(nested, "forward_bundle"), depth + 1);
        }
    }

    private void copyMessage(JsonObject message) {
        String value = copyText(message);
        boolean media = SecureMediaClipboard.hasCopyableMedia(message);
        if (value.isEmpty() && !media) return;
        if (media && !value.isEmpty()) {
            new YiyunyingDialogBuilder(this)
                .setTitle("复制快照内容")
                .setItems(new String[]{"复制文字", "复制媒体文件"}, (dialog, which) -> {
                    if (which == 0) copyPlainText(value, "这条快照文字已复制");
                    else copyMedia(message);
                })
                .setNegativeButton("取消", null)
                .show();
            return;
        }
        if (media) {
            copyMedia(message);
            return;
        }
        copyPlainText(value, "这条快照文字已复制");
    }

    private void copyPlainText(String value, String message) {
        ClipboardManager clipboard = (ClipboardManager) getSystemService(CLIPBOARD_SERVICE);
        clipboard.setPrimaryClip(ClipData.newPlainText("聊天记录", value));
        Snackbar.make(binding.getRoot(), message, Snackbar.LENGTH_SHORT).show();
    }

    private void copyMedia(JsonObject message) {
        Snackbar.make(binding.getRoot(), "正在准备媒体文件…", Snackbar.LENGTH_SHORT).show();
        SecureMediaClipboard.copyMessageMedia(this, message, (success, count, detail) -> {
            if (binding == null) return;
            Snackbar.make(binding.getRoot(), success
                ? "已复制 " + count + " 个媒体文件，可直接粘贴发送"
                : (detail == null || detail.trim().isEmpty() ? "媒体文件复制失败" : detail),
                success ? Snackbar.LENGTH_SHORT : Snackbar.LENGTH_LONG).show();
        });
    }

    private void copyAllMedia() {
        JsonArray attachments = new JsonArray();
        for (JsonObject item : source) collectAttachments(item, attachments, 0);
        if (attachments.isEmpty()) {
            Snackbar.make(binding.getRoot(), "当前快照没有可复制的媒体文件", Snackbar.LENGTH_SHORT).show();
            return;
        }
        JsonObject combined = new JsonObject();
        combined.add("attachments", attachments);
        copyMedia(combined);
    }

    private void collectAttachments(JsonObject item, JsonArray output, int depth) {
        if (item == null || depth >= 8) return;
        for (JsonElement element : Jsons.array(item, "attachments")) {
            if (element.isJsonObject()) output.add(element.getAsJsonObject().deepCopy());
        }
        JsonObject forward = Jsons.object(item, "forward_bundle");
        for (JsonElement element : Jsons.array(forward, "items")) {
            if (element.isJsonObject()) collectAttachments(element.getAsJsonObject(), output, depth + 1);
        }
    }

    private void copyAll() {
        StringBuilder text = new StringBuilder();
        for (JsonObject item : source) {
            if (text.length() > 0) text.append("\n\n");
            text.append(Jsons.string(item, "sender_name"));
            String badge = Jsons.string(item, "sender_badge");
            if (!badge.isEmpty()) text.append("【").append(badge).append("】");
            text.append("：").append(copyText(item));
        }
        if (text.length() == 0) return;
        ClipboardManager clipboard = (ClipboardManager) getSystemService(CLIPBOARD_SERVICE);
        clipboard.setPrimaryClip(ClipData.newPlainText("合并转发的聊天记录", text));
        Snackbar.make(binding.getRoot(), "全部可见文字已复制", Snackbar.LENGTH_SHORT).show();
    }

    private String copyText(JsonObject message) {
        StringBuilder value = new StringBuilder(Jsons.string(message, "content"));
        for (JsonElement element : Jsons.array(message, "attachments")) {
            if (!element.isJsonObject()) continue;
            JsonObject attachment = element.getAsJsonObject();
            String type = attachmentLabel(Jsons.string(attachment, "media_type"));
            String name = Jsons.string(attachment, "file_name");
            if (name.isEmpty()) name = Jsons.string(attachment, "original_name");
            if (value.length() > 0) value.append('\n');
            value.append('[').append(type).append(']');
            if (!name.isEmpty()) value.append(' ').append(name);
        }
        return value.toString().trim();
    }

    private static String attachmentLabel(String type) {
        if ("image".equals(type) || "gif".equals(type)) return "图片";
        if ("sticker".equals(type)) return "表情包";
        if ("video".equals(type)) return "视频";
        if ("audio".equals(type)) return "音频";
        return "文件";
    }

    @Override protected void onDestroy() {
        if (request != null) request.cancel();
        binding = null;
        super.onDestroy();
    }
}
