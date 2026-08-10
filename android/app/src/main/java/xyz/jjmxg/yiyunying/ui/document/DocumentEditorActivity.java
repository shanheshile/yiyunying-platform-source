package xyz.jjmxg.yiyunying.ui.document;

import android.content.ClipData;
import android.content.ClipboardManager;
import android.content.Context;
import android.content.Intent;
import android.content.SharedPreferences;
import android.database.Cursor;
import android.net.Uri;
import android.os.Bundle;
import android.provider.OpenableColumns;
import android.view.Gravity;
import android.view.View;
import android.view.ViewGroup;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.TextView;

import androidx.activity.OnBackPressedCallback;
import androidx.activity.result.ActivityResultLauncher;
import androidx.activity.result.ActivityResult;
import androidx.activity.result.contract.ActivityResultContracts;
import androidx.appcompat.app.AppCompatActivity;

import com.google.android.material.button.MaterialButton;
import com.google.android.material.card.MaterialCardView;
import xyz.jjmxg.yiyunying.ui.common.YiyunyingDialogBuilder;
import com.google.android.material.snackbar.Snackbar;
import com.google.gson.JsonArray;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;

import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.core.AppAccess;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.data.api.RequestHandle;
import xyz.jjmxg.yiyunying.databinding.ActivityDocumentEditorBinding;
import xyz.jjmxg.yiyunying.domain.Role;
import xyz.jjmxg.yiyunying.domain.module.ActionSpec;
import xyz.jjmxg.yiyunying.domain.module.FieldSpec;
import xyz.jjmxg.yiyunying.domain.module.FieldType;
import xyz.jjmxg.yiyunying.ui.auth.LoginActivity;
import xyz.jjmxg.yiyunying.ui.common.DynamicFormDialog;
import xyz.jjmxg.yiyunying.ui.common.ActionIconResolver;
import xyz.jjmxg.yiyunying.ui.common.ImageLoader;
import xyz.jjmxg.yiyunying.ui.upload.ContentUriRequestBody;
import xyz.jjmxg.yiyunying.ui.upload.FilePickerActivity;
import xyz.jjmxg.yiyunying.ui.upload.FilePreviewActivity;
import xyz.jjmxg.yiyunying.ui.upload.MediaPickerActivity;
import xyz.jjmxg.yiyunying.ui.upload.UploadPolicyStore;

public final class DocumentEditorActivity extends xyz.jjmxg.yiyunying.ui.common.SystemInsetActivity {
    public static final String EXTRA_DOCUMENT_ID = "document_id";
    private static final String DRAFTS = "yiyunying.document.drafts";

    private ActivityDocumentEditorBinding binding;
    private final List<Attachment> attachments = new ArrayList<>();
    private long documentId;
    private RequestHandle request;
    private RequestHandle uploadRequest;
    private String originalTitle = "";
    private String originalContent = "";
    private String originalAttachmentSignature = "";
    private boolean originalPublic;
    private boolean saved;
    private boolean favorited;
    private Role role;
    private long shareId;
    private String shareCode = "";
    private String shareUrl = "";
    private String shareExpiredAt = "";
    private boolean shareActive;
    private boolean sharePasswordRequired;
    private String pickerType = "file";

    private final ActivityResultLauncher<Intent> attachmentPicker = registerForActivityResult(
        new ActivityResultContracts.StartActivityForResult(), this::selectedAttachments);

    @Override
    protected void onCreate(Bundle state) {
        super.onCreate(state);
        if (!AppAccess.from(this).session().isAuthenticated()) { login(); return; }
        binding = ActivityDocumentEditorBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());
        role = AppAccess.from(this).session().role();
        documentId = getIntent().getLongExtra(EXTRA_DOCUMENT_ID, 0L);
        binding.toolbar.setTitle(documentId > 0 ? editorTitle() : (role == Role.ADMIN ? "新建管理员文档" : "新建笔记"));
        binding.toolbar.setNavigationOnClickListener(view -> attemptClose());
        binding.saveButton.setOnClickListener(view -> save());
        binding.addAttachmentButton.setOnClickListener(view -> showAttachmentMenu());
        binding.shareButton.setOnClickListener(view -> share());
        binding.favoriteButton.setOnClickListener(view -> toggleFavorite());
        binding.deleteButton.setOnClickListener(view -> delete());
        if (role == Role.ADMIN) {
            binding.shareButton.setVisibility(View.GONE);
            binding.favoriteButton.setVisibility(View.GONE);
            binding.addAttachmentButton.setVisibility(View.GONE);
            binding.attachmentSummary.setVisibility(View.GONE);
            binding.attachmentContainer.setVisibility(View.GONE);
        }
        getOnBackPressedDispatcher().addCallback(this, new OnBackPressedCallback(true) {
            @Override public void handleOnBackPressed() { attemptClose(); }
        });
        if (documentId > 0) load();
        else restoreDraft();
    }

    private void load() {
        setLoading(true);
        request = AppAccess.from(this).repository().get(documentPath(), new LinkedHashMap<>(), result -> {
            setLoading(false);
            if (!result.isSuccessful()) { error(result.message(), result.isAuthenticationFailure()); return; }
            JsonObject document = Jsons.object(result.dataObject(), "document");
            binding.titleInput.setText(Jsons.string(document, "title"));
            binding.contentInput.setText(Jsons.string(document, "content"));
            binding.publicSwitch.setChecked(document.has("is_public") && document.get("is_public").getAsBoolean());
            loadAttachments(document);
            favorited = role == Role.USER && document.has("favorited") && document.get("favorited").getAsBoolean();
            updateFavoriteButton();
            binding.shareButton.setVisibility(role == Role.USER ? View.VISIBLE : View.GONE);
            binding.deleteButton.setVisibility(View.VISIBLE);
            markOriginal();
            restoreDraft();
            if (role == Role.USER) loadShareState();
        });
    }

    private void save() {
        String title = text(binding.titleInput.getText());
        if (title.isEmpty()) {
            Snackbar.make(binding.getRoot(), "标题不能为空", Snackbar.LENGTH_LONG).show();
            return;
        }
        JsonObject body = new JsonObject();
        body.addProperty("title", title);
        body.addProperty("content", binding.contentInput.getText() == null ? "" : binding.contentInput.getText().toString());
        body.addProperty("content_type", "text");
        body.addProperty("is_public", binding.publicSwitch.isChecked());
        setLoading(true);
        if (role == Role.USER) {
            uploadAttachments(0, new JsonArray(), body);
            return;
        }
        submitDocument(body);
    }

    private void uploadAttachments(int index, JsonArray media, JsonObject body) {
        if (index >= attachments.size()) {
            body.add("attachments", media);
            submitDocument(body);
            return;
        }
        Attachment item = attachments.get(index);
        if (item.uri == null) {
            media.add(item.payload());
            uploadAttachments(index + 1, media, body);
            return;
        }
        Map<String, String> fields = new LinkedHashMap<>();
        fields.put("scene", "note_attachment");
        ContentUriRequestBody file = new ContentUriRequestBody(
            getContentResolver(), item.uri, item.mime, item.size);
        uploadRequest = AppAccess.from(this).repository().upload(
            "/api/user/uploads", item.name, item.mime, file, fields, result -> {
                uploadRequest = null;
                if (binding == null) return;
                if (!result.isSuccessful()) {
                    setLoading(false);
                    error(result.message().isEmpty() ? "附件上传失败" : result.message(), result.isAuthenticationFailure());
                    return;
                }
                JsonObject attachment = new JsonObject();
                attachment.addProperty("media_type", item.type);
                attachment.addProperty("upload_id", Jsons.longValue(result.dataObject(), "upload_id"));
                attachment.addProperty("file_name", item.name);
                attachment.addProperty("mime_type", item.mime);
                if (item.size > 0) attachment.addProperty("size_bytes", item.size);
                media.add(attachment);
                uploadAttachments(index + 1, media, body);
            });
    }

    private void submitDocument(JsonObject body) {
        String path = documentId > 0 ? documentPath() : documentBasePath();
        request = documentId > 0
            ? AppAccess.from(this).repository().put(path, body, this::saved)
            : AppAccess.from(this).repository().post(path, body, this::saved);
    }

    private void saved(xyz.jjmxg.yiyunying.data.api.ApiResult result) {
        setLoading(false);
        if (!result.isSuccessful()) { error(result.message(), result.isAuthenticationFailure()); return; }
        JsonObject document = Jsons.object(result.dataObject(), "document");
        if (documentId == 0) documentId = Jsons.longValue(document, "id");
        if (role == Role.USER) loadAttachments(document);
        saved = true;
        clearDraft();
        markOriginal();
        binding.toolbar.setTitle(editorTitle());
        favorited = role == Role.USER && document.has("favorited") && document.get("favorited").getAsBoolean();
        updateFavoriteButton();
        binding.shareButton.setVisibility(role == Role.USER ? View.VISIBLE : View.GONE);
        binding.deleteButton.setVisibility(View.VISIBLE);
        setResult(RESULT_OK);
        Snackbar.make(binding.getRoot(), result.message().isEmpty() ? "文档已保存" : result.message(), Snackbar.LENGTH_LONG).show();
        if (role == Role.USER) loadShareState();
    }

    private void showAttachmentMenu() {
        String[] choices = {"图片和视频", "音频", "文档与其他文件"};
        new YiyunyingDialogBuilder(this)
            .setTitle("添加附件")
            .setItems(choices, (dialog, which) -> {
                if (which == 0) pickAttachments("media", new String[]{"image/*", "video/*"});
                else if (which == 1) pickAttachments("audio", new String[]{"audio/*"});
                else pickAttachments("file", new String[]{"*/*"});
            })
            .setNegativeButton("取消", null)
            .show();
    }

    private void pickAttachments(String type, String[] mimeTypes) {
        pickerType = type;
        if ("media".equals(type)) attachmentPicker.launch(MediaPickerActivity.intent(this, true));
        else attachmentPicker.launch(FilePickerActivity.pickerIntent(this, 50));
    }

    private void selectedAttachments(ActivityResult result) {
        if (result.getResultCode() != RESULT_OK || result.getData() == null) return;
        ArrayList<Uri> uris = result.getData()
            .getParcelableArrayListExtra(MediaPickerActivity.EXTRA_SELECTED_URIS);
        if (uris == null) {
            ArrayList<String> values = result.getData()
                .getStringArrayListExtra(FilePickerActivity.EXTRA_SELECTED_URIS);
            uris = new ArrayList<>();
            if (values != null) for (String value : values) if (value != null) uris.add(Uri.parse(value));
        }
        if (uris == null) return;
        for (Uri uri : uris) {
            if (uri == null || attachments.size() >= 50 || containsAttachment(uri)) continue;
            try {
                getContentResolver().takePersistableUriPermission(uri, Intent.FLAG_GRANT_READ_URI_PERMISSION);
            } catch (RuntimeException ignored) { }
            String name = "本地附件";
            long size = -1;
            try (Cursor cursor = getContentResolver().query(uri, null, null, null, null)) {
                if (cursor != null && cursor.moveToFirst()) {
                    int nameIndex = cursor.getColumnIndex(OpenableColumns.DISPLAY_NAME);
                    int sizeIndex = cursor.getColumnIndex(OpenableColumns.SIZE);
                    if (nameIndex >= 0 && !cursor.isNull(nameIndex)) name = cursor.getString(nameIndex);
                    if (sizeIndex >= 0 && !cursor.isNull(sizeIndex)) size = cursor.getLong(sizeIndex);
                }
            }
            String mime = getContentResolver().getType(uri);
            if (mime == null || mime.trim().isEmpty()) mime = "application/octet-stream";
            String type = mediaType(mime, pickerType);
            if (!UploadPolicyStore.accepts(this, type, size)) {
                Snackbar.make(binding.getRoot(), UploadPolicyStore.rejectionMessage(this, type, size), Snackbar.LENGTH_LONG).show();
                continue;
            }
            attachments.add(Attachment.local(uri, type, name, mime, size));
        }
        renderAttachments();
    }

    private boolean containsAttachment(Uri uri) {
        for (Attachment attachment : attachments) if (uri.equals(attachment.uri)) return true;
        return false;
    }

    private String mediaType(String mime, String requested) {
        String value = mime.toLowerCase();
        if (value.startsWith("image/")) return "image";
        if (value.startsWith("video/")) return "video";
        if (value.startsWith("audio/")) return "audio";
        return "media".equals(requested) ? "file" : requested;
    }

    private void loadAttachments(JsonObject document) {
        attachments.clear();
        for (JsonElement element : Jsons.array(document, "attachments")) {
            if (element.isJsonObject()) attachments.add(Attachment.remote(element.getAsJsonObject()));
        }
        renderAttachments();
    }

    private void renderAttachments() {
        if (binding == null || role != Role.USER) return;
        binding.attachmentContainer.removeAllViews();
        binding.attachmentSummary.setText(attachments.isEmpty()
            ? "尚未添加附件"
            : "已添加 " + attachments.size() + " 个附件，保存笔记后同步上传");
        for (int index = 0; index < attachments.size(); index++) {
            Attachment attachment = attachments.get(index);
            MaterialCardView card = new MaterialCardView(this);
            LinearLayout.LayoutParams cardParams = new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, dp(76));
            cardParams.bottomMargin = dp(8);
            card.setLayoutParams(cardParams);
            card.setRadius(dp(6));
            card.setCardElevation(0f);
            card.setCardBackgroundColor(getColor(R.color.surface_container));

            LinearLayout row = new LinearLayout(this);
            row.setGravity(Gravity.CENTER_VERTICAL);
            row.setPadding(dp(10), dp(8), dp(6), dp(8));
            ImageView preview = new ImageView(this);
            preview.setScaleType(ImageView.ScaleType.CENTER_CROP);
            preview.setImageResource(typeIcon(attachment.type));
            if ("image".equals(attachment.type)) {
                if (attachment.uri != null) preview.setImageURI(attachment.uri);
                else ImageLoader.get().load(
                    ImageLoader.get().absoluteUrl(this, attachment.previewUrl()), preview, R.drawable.ic_file);
            }
            row.addView(preview, new LinearLayout.LayoutParams(dp(54), dp(54)));

            TextView details = new TextView(this);
            details.setText(typeLabel(attachment.type) + "\n" + attachment.name
                + (attachment.size > 0 ? " · " + readableSize(attachment.size) : ""));
            details.setMaxLines(2);
            LinearLayout.LayoutParams detailParams = new LinearLayout.LayoutParams(
                0, ViewGroup.LayoutParams.MATCH_PARENT, 1f);
            detailParams.leftMargin = dp(10);
            row.addView(details, detailParams);

            MaterialButton previewButton = new MaterialButton(this);
            previewButton.setText("查看");
            previewButton.setOnClickListener(view -> previewAttachment(attachment));
            row.addView(previewButton, new LinearLayout.LayoutParams(dp(66), dp(48)));

            MaterialButton removeButton = new MaterialButton(this);
            removeButton.setText("移除");
            ActionIconResolver.apply(removeButton, "移除附件 " + attachment.name, 0);
            int target = index;
            removeButton.setOnClickListener(view -> {
                attachments.remove(target);
                renderAttachments();
            });
            row.addView(removeButton, new LinearLayout.LayoutParams(dp(66), dp(48)));
            card.addView(row);
            binding.attachmentContainer.addView(card);
        }
    }

    private void previewAttachment(Attachment attachment) {
        if (attachment.uri != null) {
            try {
                Intent intent = new Intent(Intent.ACTION_VIEW)
                    .setDataAndType(attachment.uri, attachment.mime)
                    .addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION);
                startActivity(Intent.createChooser(intent, "预览附件"));
            } catch (RuntimeException exception) {
                Snackbar.make(binding.getRoot(), "当前设备没有可预览此文件的应用", Snackbar.LENGTH_LONG).show();
            }
            return;
        }
        FilePreviewActivity.open(this, attachment.name, attachment.url(), attachment.mime);
    }

    private int typeIcon(String type) {
        if ("video".equals(type)) return R.drawable.ic_video;
        return R.drawable.ic_file;
    }

    private String typeLabel(String type) {
        if ("image".equals(type)) return "图片";
        if ("video".equals(type)) return "视频";
        if ("audio".equals(type)) return "音频";
        return "文件";
    }

    private String readableSize(long size) {
        if (size < 1024) return size + " B";
        if (size < 1024 * 1024) return String.format(java.util.Locale.CHINA, "%.1f KB", size / 1024d);
        return String.format(java.util.Locale.CHINA, "%.1f MB", size / (1024d * 1024d));
    }

    private void toggleFavorite() {
        if (role != Role.USER || documentId <= 0) return;
        setLoading(true);
        request = AppAccess.from(this).repository().post(
            "/api/user/notes/" + documentId + "/favorite",
            new JsonObject(),
            result -> {
                request = null;
                if (binding == null) return;
                setLoading(false);
                if (!result.isSuccessful()) {
                    error(result.message().isEmpty() ? "收藏操作失败" : result.message(), result.isAuthenticationFailure());
                    return;
                }
                favorited = result.dataObject().has("favorited")
                    && result.dataObject().get("favorited").getAsBoolean();
                updateFavoriteButton();
                Snackbar.make(binding.getRoot(),
                    result.message().isEmpty() ? (favorited ? "笔记已收藏" : "已取消收藏笔记") : result.message(),
                    Snackbar.LENGTH_SHORT).show();
            }
        );
    }

    private void updateFavoriteButton() {
        if (binding == null) return;
        boolean visible = role == Role.USER && documentId > 0;
        binding.favoriteButton.setVisibility(visible ? View.VISIBLE : View.GONE);
        binding.favoriteButton.setText(favorited ? "取消收藏" : "收藏笔记");
        ActionIconResolver.apply(binding.favoriteButton,
            favorited ? "取消收藏这篇笔记" : "收藏这篇笔记", 0);
    }
    private void share() {
        if (role != Role.USER) return;
        if (documentId <= 0) {
            Snackbar.make(binding.getRoot(), "请先保存笔记，再创建分享码", Snackbar.LENGTH_LONG).show();
            return;
        }
        if (!shareCode.isEmpty() && shareActive) {
            showShareMenu();
            return;
        }
        configureShare();
    }

    private void configureShare() {
        ActionSpec action = ActionSpec.builder(
                shareCode.isEmpty() ? "创建固定分享码" : "重新启用固定分享码",
                "POST",
                "/api/user/notes/" + documentId + "/share"
            )
            .fields(
                FieldSpec.typed("password", "访问密码（可选）", FieldType.PASSWORD, false),
                FieldSpec.typed("expired_at", "到期时间（可选）", FieldType.DATE_TIME, false)
            ).build();
        DynamicFormDialog.show(this, action, null, body -> {
            setLoading(true);
            request = AppAccess.from(this).repository().post(action.pathTemplate(), body, result -> {
                setLoading(false);
                if (!result.isSuccessful()) { error(result.message(), result.isAuthenticationFailure()); return; }
                JsonObject share = Jsons.object(result.dataObject(), "share");
                applyShare(share);
                showShareMenu();
            });
        });
    }

    private void loadShareState() {
        if (documentId <= 0 || role != Role.USER) return;
        request = AppAccess.from(this).repository().get(
            "/api/user/notes/" + documentId + "/share",
            new LinkedHashMap<>(),
            result -> {
                if (binding == null) return;
                if (!result.isSuccessful()) {
                    if (result.isAuthenticationFailure()) error(result.message(), true);
                    return;
                }
                applyShare(Jsons.object(result.dataObject(), "share"));
            }
        );
    }

    private void applyShare(JsonObject share) {
        shareId = Jsons.longValue(share, "id");
        shareCode = Jsons.string(share, "share_code");
        shareUrl = Jsons.string(share, "share_url");
        shareExpiredAt = Jsons.string(share, "expired_at");
        shareActive = Jsons.intValue(share, "status", 0) == 1;
        sharePasswordRequired = share.has("password_required")
            && !share.get("password_required").isJsonNull()
            && share.get("password_required").getAsBoolean();
        if (shareUrl.isEmpty() && !shareCode.isEmpty()) shareUrl = localShareUrl();
        updateShareButton();
    }

    private void updateShareButton() {
        if (binding == null || role != Role.USER || documentId <= 0) return;
        String shareAction;
        if (shareCode.isEmpty()) shareAction = "创建固定分享码";
        else if (shareActive) shareAction = "查看固定分享码";
        else shareAction = "重新启用固定分享码";
        binding.shareButton.setText(shareAction);
        ActionIconResolver.apply(binding.shareButton, shareAction, R.drawable.ic_forward);
    }

    private void showShareMenu() {
        if (shareCode.isEmpty()) {
            configureShare();
            return;
        }
        String state = shareActive ? "可访问" : "已停用";
        String details = "分享码：" + shareCode
            + "\n状态：" + state
            + (sharePasswordRequired ? "\n访问密码：已设置" : "\n访问密码：未设置")
            + (shareExpiredAt.isEmpty() ? "\n有效期：长期有效" : "\n到期：" + shareExpiredAt)
            + "\n\n使用方法：对方在“我的笔记”页面点击剪贴板图标，粘贴分享码或完整链接即可打开。";
        String[] actions = shareActive
            ? new String[]{"复制分享码", "复制完整链接", "系统分享", "修改分享设置", "停用分享"}
            : new String[]{"复制分享码", "复制完整链接", "重新启用分享"};
        new YiyunyingDialogBuilder(this)
            .setTitle("固定分享码")
            .setMessage(details)
            .setItems(actions, (dialog, which) -> {
                if (which == 0) copy("分享码", shareCode);
                else if (which == 1) copy("分享链接", shareUrl);
                else if (shareActive && which == 2) systemShare();
                else if (shareActive && which == 3) configureShare();
                else if (shareActive && which == 4) confirmDisableShare();
                else if (!shareActive && which == 2) configureShare();
            })
            .setPositiveButton("完成", null)
            .show();
    }

    private void confirmDisableShare() {
        new YiyunyingDialogBuilder(this)
            .setTitle("停用分享")
            .setMessage("停用后外部无法访问，但固定分享码会保留；下次启用仍使用同一个码。")
            .setNegativeButton("取消", null)
            .setPositiveButton("停用", (dialog, which) -> {
                setLoading(true);
                request = AppAccess.from(this).repository().delete(
                    "/api/user/note-shares/" + shareId,
                    new JsonObject(),
                    result -> {
                        setLoading(false);
                        if (!result.isSuccessful()) { error(result.message(), result.isAuthenticationFailure()); return; }
                        shareActive = false;
                        updateShareButton();
                        Snackbar.make(binding.getRoot(), "分享已停用，分享码已保留", Snackbar.LENGTH_LONG).show();
                    }
                );
            })
            .show();
    }

    private void systemShare() {
        String text = "分享笔记：" + text(binding.titleInput.getText())
            + "\n分享码：" + shareCode + "\n" + shareUrl;
        Intent intent = new Intent(Intent.ACTION_SEND);
        intent.setType("text/plain");
        intent.putExtra(Intent.EXTRA_TEXT, text);
        startActivity(Intent.createChooser(intent, "分享笔记"));
    }

    private void delete() {
        new YiyunyingDialogBuilder(this)
            .setTitle("删除文档")
            .setMessage("删除后管理员仍可按后台规则恢复，是否继续？")
            .setNegativeButton("取消", null)
            .setPositiveButton("删除", (dialog, which) -> {
                setLoading(true);
                request = AppAccess.from(this).repository().delete(documentPath(), new JsonObject(), result -> {
                    setLoading(false);
                    if (!result.isSuccessful()) { error(result.message(), result.isAuthenticationFailure()); return; }
                    saved = true;
                    clearDraft();
                    setResult(RESULT_OK);
                    finish();
                });
            }).show();
    }

    private void attemptClose() {
        if (!dirty()) { finish(); return; }
        saveDraft();
        new YiyunyingDialogBuilder(this)
            .setTitle("保留未保存内容")
            .setMessage("内容已保存为本地草稿。退出编辑？")
            .setNegativeButton("继续编辑", null)
            .setPositiveButton("退出", (dialog, which) -> finish())
            .show();
    }

    private boolean dirty() {
        if (binding == null) return false;
        return !originalTitle.equals(text(binding.titleInput.getText()))
            || !originalContent.equals(binding.contentInput.getText() == null ? "" : binding.contentInput.getText().toString())
            || originalPublic != binding.publicSwitch.isChecked()
            || !originalAttachmentSignature.equals(attachmentSignature());
    }

    private void markOriginal() {
        originalTitle = text(binding.titleInput.getText());
        originalContent = binding.contentInput.getText() == null ? "" : binding.contentInput.getText().toString();
        originalPublic = binding.publicSwitch.isChecked();
        originalAttachmentSignature = attachmentSignature();
    }

    private void saveDraft() {
        if (!dirty()) return;
        JsonObject draft = new JsonObject();
        draft.addProperty("title", text(binding.titleInput.getText()));
        draft.addProperty("content", binding.contentInput.getText() == null ? "" : binding.contentInput.getText().toString());
        draft.addProperty("is_public", binding.publicSwitch.isChecked());
        JsonArray attachmentValues = new JsonArray();
        for (Attachment attachment : attachments) attachmentValues.add(attachment.serialized());
        draft.add("attachments", attachmentValues);
        getSharedPreferences(DRAFTS, MODE_PRIVATE).edit().putString(draftKey(), Jsons.GSON.toJson(draft)).apply();
    }

    private void restoreDraft() {
        String raw = getSharedPreferences(DRAFTS, MODE_PRIVATE).getString(draftKey(), "");
        if (raw == null || raw.isEmpty()) { markOriginal(); return; }
        try {
            JsonObject draft = Jsons.parse(raw).getAsJsonObject();
            binding.titleInput.setText(Jsons.string(draft, "title"));
            binding.contentInput.setText(Jsons.string(draft, "content"));
            binding.publicSwitch.setChecked(draft.has("is_public") && draft.get("is_public").getAsBoolean());
            if (draft.has("attachments") && draft.get("attachments").isJsonArray()) {
                attachments.clear();
                for (JsonElement element : draft.getAsJsonArray("attachments")) {
                    if (!element.isJsonObject()) continue;
                    JsonObject value = element.getAsJsonObject();
                    String localUri = Jsons.string(value, "local_uri");
                    if (!localUri.isEmpty()) {
                        attachments.add(Attachment.local(
                            Uri.parse(localUri),
                            Jsons.string(value, "media_type"),
                            Jsons.string(value, "file_name"),
                            Jsons.string(value, "mime_type"),
                            Jsons.longValue(value, "size_bytes")
                        ));
                        continue;
                    }
                    JsonObject remote = Jsons.object(value, "remote");
                    if (remote.size() > 0) attachments.add(Attachment.remote(remote));
                }
                renderAttachments();
            }
            Snackbar.make(binding.getRoot(), "已恢复本地草稿", Snackbar.LENGTH_LONG).show();
        } catch (RuntimeException ignored) { clearDraft(); }
    }

    private void clearDraft() {
        getSharedPreferences(DRAFTS, MODE_PRIVATE).edit()
            .remove(draftKey())
            .remove(draftPrefix() + "new")
            .apply();
    }
    private String draftPrefix() {
        return role.wireName() + ":" + AppAccess.from(this).session().account() + ":"
            + AppAccess.from(this).session().appKey() + ":";
    }
    private String draftKey() { return draftPrefix() + (documentId > 0 ? documentId : "new"); }
    private String documentBasePath() {
        if (role == Role.ADMIN) {
            return "/api/admin/apps/" + AppAccess.from(this).session().selectedAppId() + "/documents";
        }
        return "/api/user/notes";
    }
    private String documentPath() { return documentBasePath() + "/" + documentId; }
    private void copy(String label, String value) {
        ClipboardManager manager = (ClipboardManager) getSystemService(Context.CLIPBOARD_SERVICE);
        if (manager != null) manager.setPrimaryClip(ClipData.newPlainText(label, value));
        Snackbar.make(binding.getRoot(), label + "已复制", Snackbar.LENGTH_SHORT).show();
    }

    private String localShareUrl() {
        String base = AppAccess.from(this).session().baseUrl();
        return (base.endsWith("/") ? base : base + "/") + "api/public/note-shares/" + shareCode;
    }

    private String editorTitle() {
        return role == Role.USER ? "编辑笔记" : "编辑文档";
    }
    private void setLoading(boolean loading) {
        binding.progress.setVisibility(loading ? View.VISIBLE : View.INVISIBLE);
        binding.saveButton.setEnabled(!loading);
        binding.shareButton.setEnabled(!loading);
        binding.favoriteButton.setEnabled(!loading);
        binding.deleteButton.setEnabled(!loading);
        binding.addAttachmentButton.setEnabled(!loading);
    }
    private void error(String message, boolean auth) {
        if (auth) { AppAccess.from(this).session().clearAuthentication(); login(); return; }
        Snackbar.make(binding.getRoot(), message == null || message.isEmpty() ? "操作失败" : message, Snackbar.LENGTH_LONG).show();
    }
    private void login() {
        startActivity(new Intent(this, LoginActivity.class).putExtra(LoginActivity.EXTRA_FORCE_LOGIN, true)
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TASK));
        finish();
    }
    private static String text(CharSequence value) { return value == null ? "" : value.toString().trim(); }

    @Override protected void onStop() {
        if (binding != null) saveDraft();
        super.onStop();
    }
    @Override protected void onDestroy() {
        if (request != null) request.cancel();
        if (uploadRequest != null) uploadRequest.cancel();
        binding = null;
        super.onDestroy();
    }

    private String attachmentSignature() {
        JsonArray values = new JsonArray();
        for (Attachment attachment : attachments) values.add(attachment.serialized());
        return Jsons.GSON.toJson(values);
    }

    private int dp(int value) {
        return Math.round(value * getResources().getDisplayMetrics().density);
    }

    private static final class Attachment {
        private final Uri uri;
        private final String type;
        private final String name;
        private final String mime;
        private final long size;
        private final JsonObject remote;

        private Attachment(Uri uri, String type, String name, String mime, long size, JsonObject remote) {
            this.uri = uri;
            this.type = type == null || type.isEmpty() ? "file" : type;
            this.name = name == null || name.isEmpty() ? "附件" : name;
            this.mime = mime == null || mime.isEmpty() ? "application/octet-stream" : mime;
            this.size = size;
            this.remote = remote;
        }

        private static Attachment local(Uri uri, String type, String name, String mime, long size) {
            return new Attachment(uri, type, name, mime, size, null);
        }

        private static Attachment remote(JsonObject value) {
            JsonObject copy = value == null ? new JsonObject() : value.deepCopy();
            return new Attachment(
                null,
                Jsons.string(copy, "media_type"),
                Jsons.string(copy, "file_name"),
                Jsons.string(copy, "mime_type"),
                Jsons.longValue(copy, "size_bytes"),
                copy
            );
        }

        private JsonObject payload() {
            if (remote != null) return remote.deepCopy();
            JsonObject value = new JsonObject();
            value.addProperty("media_type", type);
            value.addProperty("file_name", name);
            value.addProperty("mime_type", mime);
            if (size > 0) value.addProperty("size_bytes", size);
            return value;
        }

        private String url() {
            return remote == null ? "" : Jsons.string(remote, "url");
        }

        private String previewUrl() {
            if (remote == null) return "";
            String preview = Jsons.string(remote, "thumbnail_url");
            return preview.isEmpty() ? Jsons.string(remote, "url") : preview;
        }

        private JsonObject serialized() {
            JsonObject value = new JsonObject();
            if (uri != null) {
                value.addProperty("local_uri", uri.toString());
                value.addProperty("media_type", type);
                value.addProperty("file_name", name);
                value.addProperty("mime_type", mime);
                if (size > 0) value.addProperty("size_bytes", size);
            } else if (remote != null) {
                value.add("remote", remote.deepCopy());
            }
            return value;
        }
    }
}
