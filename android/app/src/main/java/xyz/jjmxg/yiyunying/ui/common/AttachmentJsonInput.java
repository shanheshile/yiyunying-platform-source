package xyz.jjmxg.yiyunying.ui.common;

import android.content.ContentResolver;
import android.content.Context;
import android.database.Cursor;
import android.graphics.Color;
import android.net.Uri;
import android.provider.OpenableColumns;
import android.view.Gravity;
import android.view.View;
import android.view.ViewGroup;
import android.webkit.MimeTypeMap;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.TextView;

import com.google.android.material.button.MaterialButton;
import com.google.android.material.card.MaterialCardView;
import com.google.gson.JsonArray;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;

import java.util.ArrayList;
import java.util.Collections;
import java.util.LinkedHashMap;
import java.util.LinkedHashSet;
import java.util.List;
import java.util.Locale;
import java.util.Map;
import java.util.Set;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.core.AppAccess;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.data.api.RequestHandle;
import xyz.jjmxg.yiyunying.domain.Role;
import xyz.jjmxg.yiyunying.domain.module.FieldSpec;
import xyz.jjmxg.yiyunying.ui.upload.ContentUriRequestBody;

/** Visual attachment editor that submits upload identifiers and never raw server URLs. */
public final class AttachmentJsonInput extends LinearLayout {
    private final FieldSpec field;
    private final LinearLayout itemContainer;
    private final TextView emptyView;
    private final TextView errorView;
    private final TextView noticeView;
    private final List<AttachmentItem> items = new ArrayList<>();
    private final List<RequestHandle> requests = new ArrayList<>();
    private final Set<String> knownUris = new LinkedHashSet<>();
    private boolean detached;

    public AttachmentJsonInput(Context context, FieldSpec field, JsonElement initial) {
        super(context);
        this.field = field;
        setOrientation(VERTICAL);
        setFocusable(true);
        setFocusableInTouchMode(true);

        TextView label = new TextView(context);
        label.setText(field.label() + (field.required() ? " *" : ""));
        label.setTextAppearance(com.google.android.material.R.style.TextAppearance_Material3_TitleSmall);
        label.setTextColor(context.getColor(R.color.on_surface));
        addView(label, new LayoutParams(-1, -2));

        TextView helper = new TextView(context);
        helper.setText("复制软件内媒体后可直接粘贴。这里只保存文件编号，不保存或展示服务器地址。");
        helper.setTextAppearance(com.google.android.material.R.style.TextAppearance_Material3_BodySmall);
        helper.setTextColor(context.getColor(R.color.on_surface_variant));
        LayoutParams helperParams = new LayoutParams(-1, -2);
        helperParams.topMargin = dp(4);
        addView(helper, helperParams);

        LinearLayout actions = new LinearLayout(context);
        actions.setOrientation(HORIZONTAL);
        actions.setGravity(Gravity.START | Gravity.CENTER_VERTICAL);
        MaterialButton paste = new MaterialButton(context);
        paste.setText("粘贴已复制媒体");
        paste.setIconResource(R.drawable.ic_content_paste);
        paste.setContentDescription("粘贴已复制的图片、视频、音频或文件");
        paste.setOnClickListener(view -> pasteClipboard());
        actions.addView(paste, new LayoutParams(-2, dp(48)));
        LayoutParams actionsParams = new LayoutParams(-1, -2);
        actionsParams.topMargin = dp(8);
        addView(actions, actionsParams);

        noticeView = new TextView(context);
        noticeView.setTextAppearance(com.google.android.material.R.style.TextAppearance_Material3_BodySmall);
        noticeView.setTextColor(context.getColor(R.color.tertiary));
        noticeView.setVisibility(GONE);
        addView(noticeView, new LayoutParams(-1, -2));

        itemContainer = new LinearLayout(context);
        itemContainer.setOrientation(VERTICAL);
        LayoutParams listParams = new LayoutParams(-1, -2);
        listParams.topMargin = dp(6);
        addView(itemContainer, listParams);

        emptyView = new TextView(context);
        emptyView.setText("暂未添加附件");
        emptyView.setTextColor(context.getColor(R.color.outline));
        emptyView.setGravity(Gravity.CENTER);
        emptyView.setPadding(0, dp(18), 0, dp(18));
        itemContainer.addView(emptyView, new LayoutParams(-1, -2));

        errorView = new TextView(context);
        errorView.setTextColor(context.getColor(R.color.error));
        errorView.setTextAppearance(com.google.android.material.R.style.TextAppearance_Material3_BodySmall);
        errorView.setVisibility(GONE);
        LayoutParams errorParams = new LayoutParams(-1, -2);
        errorParams.topMargin = dp(4);
        addView(errorView, errorParams);

        loadInitial(initial);
        SecureMediaClipboard.attachPaste(this, this::addUris);
        renderItems();
    }

    public JsonElement value() {
        JsonArray result = new JsonArray();
        for (AttachmentItem item : items) {
            if (item.state == State.UPLOADING || item.state == State.PENDING) {
                throw new IllegalArgumentException("附件仍在上传，请稍候");
            }
            if (item.state == State.FAILED) {
                throw new IllegalArgumentException("存在上传失败的附件，请移除后重新添加");
            }
            if (item.value != null) result.add(item.value.deepCopy());
        }
        return result;
    }

    public boolean isEmpty() {
        return items.isEmpty();
    }

    public void showError(String message) {
        String text = message == null ? "附件内容有误" : message.trim();
        errorView.setText(text.isEmpty() ? "附件内容有误" : text);
        errorView.setVisibility(VISIBLE);
    }

    private void clearError() {
        errorView.setText("");
        errorView.setVisibility(GONE);
    }

    private void pasteClipboard() {
        List<Uri> uris = SecureMediaClipboard.clipboardMediaUris(getContext());
        if (uris.isEmpty()) {
            showError("剪贴板中没有可读取的媒体文件。请先在软件内复制图片、视频、音频或文件");
            return;
        }
        addUris(uris);
    }

    private void addUris(List<Uri> uris) {
        if (uris == null || uris.isEmpty()) return;
        clearError();
        int added = 0;
        for (Uri uri : uris) {
            if (uri == null || !"content".equalsIgnoreCase(uri.getScheme())) continue;
            if (!knownUris.add(uri.toString())) continue;
            AttachmentItem item = describe(uri);
            items.add(item);
            added++;
        }
        if (added == 0) {
            showError("没有发现新的可上传媒体");
            return;
        }
        renderItems();
        uploadNext();
    }

    private AttachmentItem describe(Uri uri) {
        ContentResolver resolver = getContext().getContentResolver();
        String name = "附件";
        long size = -1L;
        try (Cursor cursor = resolver.query(uri, new String[]{OpenableColumns.DISPLAY_NAME, OpenableColumns.SIZE}, null, null, null)) {
            if (cursor != null && cursor.moveToFirst()) {
                int nameIndex = cursor.getColumnIndex(OpenableColumns.DISPLAY_NAME);
                int sizeIndex = cursor.getColumnIndex(OpenableColumns.SIZE);
                if (nameIndex >= 0 && !cursor.isNull(nameIndex)) name = cursor.getString(nameIndex);
                if (sizeIndex >= 0 && !cursor.isNull(sizeIndex)) size = cursor.getLong(sizeIndex);
            }
        } catch (RuntimeException ignored) { }
        if (name == null || name.trim().isEmpty()) name = "附件";
        String mime = resolver.getType(uri);
        if (mime == null || mime.trim().isEmpty()) mime = mimeFromName(name);
        return new AttachmentItem(uri, name, mime, size);
    }

    private void uploadNext() {
        if (detached) return;
        for (AttachmentItem item : items) {
            if (item.state == State.UPLOADING) return;
        }
        AttachmentItem pending = null;
        for (AttachmentItem item : items) {
            if (item.state == State.PENDING) {
                pending = item;
                break;
            }
        }
        if (pending == null) return;
        String path = uploadPath();
        if (path.isEmpty()) {
            pending.state = State.FAILED;
            pending.status = "请先选择要管理的应用";
            renderItems();
            uploadNext();
            return;
        }
        pending.state = State.UPLOADING;
        pending.status = "正在上传";
        renderItems();
        AttachmentItem current = pending;
        Map<String, String> fields = new LinkedHashMap<>();
        fields.put("scene", "dynamic_form_attachment");
        fields.put("original_upload", "1");
        RequestHandle request = AppAccess.from(getContext()).repository().upload(
            path,
            current.name,
            current.mime,
            new ContentUriRequestBody(getContext().getContentResolver(), current.uri, current.mime, current.size),
            fields,
            result -> {
                if (detached || current.state != State.UPLOADING) return;
                if (!result.isSuccessful()) {
                    current.state = State.FAILED;
                    current.status = result.message().isEmpty() ? "上传失败" : result.message();
                } else {
                    JsonObject uploaded = result.dataObject();
                    long uploadId = Jsons.longValue(uploaded, "upload_id");
                    if (uploadId <= 0) {
                        current.state = State.FAILED;
                        current.status = "服务器未返回文件编号";
                    } else {
                        current.value = safeValue(uploadId, current.name, current.mime,
                            Math.max(0L, Jsons.longValue(uploaded, "size_bytes") > 0
                                ? Jsons.longValue(uploaded, "size_bytes") : current.size), uploaded);
                        current.state = State.READY;
                        current.status = Jsons.string(uploaded, "reused").equals("true") ? "已复用文件" : "上传完成";
                    }
                }
                renderItems();
                uploadNext();
            }
        );
        current.request = request;
        requests.add(request);
    }

    private String uploadPath() {
        Role role = AppAccess.from(getContext()).session().role();
        if (role == Role.USER) return "/api/user/uploads";
        long appId = AppAccess.from(getContext()).session().selectedAppId();
        if (appId <= 0) return "";
        if (role == Role.PLATFORM) return "/api/platform/apps/" + appId + "/uploads";
        return "/api/admin/apps/" + appId + "/uploads";
    }

    private void renderItems() {
        itemContainer.removeAllViews();
        if (items.isEmpty()) {
            if (emptyView.getParent() != null) ((ViewGroup) emptyView.getParent()).removeView(emptyView);
            itemContainer.addView(emptyView, new LayoutParams(-1, -2));
            return;
        }
        for (AttachmentItem item : new ArrayList<>(items)) itemContainer.addView(itemView(item));
    }

    private View itemView(AttachmentItem item) {
        MaterialCardView card = new MaterialCardView(getContext());
        card.setRadius(dp(6));
        card.setCardElevation(0f);
        card.setStrokeWidth(dp(1));
        card.setStrokeColor(getContext().getColor(item.state == State.FAILED ? R.color.error : R.color.outline));
        card.setCardBackgroundColor(getContext().getColor(R.color.surface_container));
        LayoutParams cardParams = new LayoutParams(-1, -2);
        cardParams.bottomMargin = dp(8);
        card.setLayoutParams(cardParams);

        LinearLayout row = new LinearLayout(getContext());
        row.setOrientation(HORIZONTAL);
        row.setGravity(Gravity.CENTER_VERTICAL);
        row.setPadding(dp(12), dp(9), dp(6), dp(9));
        ImageView icon = new ImageView(getContext());
        icon.setImageResource(R.drawable.ic_file);
        icon.setColorFilter(ThemeColors.primary(getContext()));
        row.addView(icon, new LinearLayout.LayoutParams(dp(28), dp(28)));

        LinearLayout copy = new LinearLayout(getContext());
        copy.setOrientation(VERTICAL);
        TextView name = new TextView(getContext());
        name.setText(item.name);
        name.setTextColor(getContext().getColor(R.color.on_surface));
        name.setTextAppearance(com.google.android.material.R.style.TextAppearance_Material3_BodyMedium);
        name.setMaxLines(2);
        copy.addView(name, new LayoutParams(-1, -2));
        TextView status = new TextView(getContext());
        status.setText(typeLabel(item.mime) + " · " + sizeText(item.size) + " · " + item.status);
        status.setTextColor(getContext().getColor(item.state == State.FAILED ? R.color.error : R.color.on_surface_variant));
        status.setTextAppearance(com.google.android.material.R.style.TextAppearance_Material3_BodySmall);
        copy.addView(status, new LayoutParams(-1, -2));
        LinearLayout.LayoutParams copyParams = new LinearLayout.LayoutParams(0, -2, 1f);
        copyParams.leftMargin = dp(10);
        row.addView(copy, copyParams);

        MaterialButton remove = new MaterialButton(getContext());
        remove.setText("");
        remove.setIconResource(R.drawable.ic_close);
        remove.setIconPadding(0);
        remove.setMinWidth(dp(44));
        remove.setMinimumWidth(dp(44));
        remove.setContentDescription("移除附件 " + item.name);
        remove.setOnClickListener(view -> remove(item));
        row.addView(remove, new LinearLayout.LayoutParams(dp(44), dp(44)));
        card.addView(row);
        return card;
    }

    private void remove(AttachmentItem item) {
        if (item.request != null) item.request.cancel();
        if (item.uri != null) knownUris.remove(item.uri.toString());
        items.remove(item);
        clearError();
        renderItems();
        uploadNext();
    }

    private void loadInitial(JsonElement initial) {
        if (initial == null || initial.isJsonNull()) return;
        JsonArray array = initial.isJsonArray() ? initial.getAsJsonArray() : new JsonArray();
        int dropped = 0;
        for (JsonElement element : array) {
            if (!element.isJsonObject()) {
                dropped++;
                continue;
            }
            JsonObject raw = element.getAsJsonObject();
            long uploadId = Jsons.longValue(raw, "upload_id");
            long stickerId = Jsons.longValue(raw, "sticker_id");
            if (uploadId <= 0 && stickerId <= 0) {
                dropped++;
                continue;
            }
            String name = firstNonEmpty(Jsons.string(raw, "file_name"), Jsons.string(raw, "original_name"), "已上传附件");
            String mime = firstNonEmpty(Jsons.string(raw, "mime_type"), "application/octet-stream");
            long size = Math.max(0L, Jsons.longValue(raw, "size_bytes"));
            JsonObject safe = safeValue(uploadId, name, mime, size, raw);
            if (stickerId > 0) safe.addProperty("sticker_id", stickerId);
            AttachmentItem item = new AttachmentItem(null, name, mime, size);
            item.state = State.READY;
            item.status = "已上传";
            item.value = safe;
            items.add(item);
        }
        if (dropped > 0) {
            noticeView.setText("已忽略 " + dropped + " 个仅含网络地址的旧附件，请重新复制媒体后添加");
            noticeView.setVisibility(VISIBLE);
        }
    }

    private JsonObject safeValue(long uploadId, String name, String mime, long size, JsonObject source) {
        JsonObject value = new JsonObject();
        if (uploadId > 0) value.addProperty("upload_id", uploadId);
        value.addProperty("media_type", mediaType(mime, name));
        value.addProperty("file_name", name);
        value.addProperty("mime_type", mime);
        value.addProperty("size_bytes", Math.max(0L, size));
        copyNumber(source, value, "width");
        copyNumber(source, value, "height");
        copyNumber(source, value, "duration_ms");
        if (source != null && source.has("is_animated") && !source.get("is_animated").isJsonNull()) {
            try { value.addProperty("is_animated", source.get("is_animated").getAsBoolean()); }
            catch (RuntimeException ignored) { }
        }
        return value;
    }

    private static void copyNumber(JsonObject source, JsonObject target, String key) {
        if (source == null || !source.has(key) || source.get(key).isJsonNull()) return;
        try { target.addProperty(key, source.get(key).getAsLong()); }
        catch (RuntimeException ignored) { }
    }

    private static String mediaType(String mime, String name) {
        String value = mime == null ? "" : mime.toLowerCase(Locale.ROOT);
        String lowerName = name == null ? "" : name.toLowerCase(Locale.ROOT);
        if (value.startsWith("image/") || lowerName.matches(".*\\.(jpg|jpeg|png|gif|webp|bmp|heic|heif)$")) return "image";
        if (value.startsWith("video/") || lowerName.matches(".*\\.(mp4|webm|mov|mkv|avi|3gp|m4v)$")) return "video";
        if (value.startsWith("audio/") || lowerName.matches(".*\\.(mp3|m4a|aac|wav|ogg|opus|flac)$")) return "audio";
        return "file";
    }

    private static String typeLabel(String mime) {
        if (mime == null) return "文件";
        if (mime.startsWith("image/")) return "图片";
        if (mime.startsWith("video/")) return "视频";
        if (mime.startsWith("audio/")) return "音频";
        if (mime.startsWith("text/") || mime.contains("pdf") || mime.contains("document") || mime.contains("sheet")) return "文档";
        return "文件";
    }

    private static String mimeFromName(String name) {
        String extension = MimeTypeMap.getFileExtensionFromUrl(name == null ? "" : name.replace(" ", "%20"));
        String mime = MimeTypeMap.getSingleton().getMimeTypeFromExtension(extension == null ? "" : extension.toLowerCase(Locale.ROOT));
        return mime == null || mime.isEmpty() ? "application/octet-stream" : mime;
    }

    private static String sizeText(long bytes) {
        if (bytes < 0) return "大小未知";
        if (bytes < 1024) return bytes + " B";
        if (bytes < 1024L * 1024L) return String.format(Locale.CHINA, "%.1f KB", bytes / 1024f);
        if (bytes < 1024L * 1024L * 1024L) return String.format(Locale.CHINA, "%.1f MB", bytes / 1024f / 1024f);
        return String.format(Locale.CHINA, "%.2f GB", bytes / 1024f / 1024f / 1024f);
    }

    private static String firstNonEmpty(String... values) {
        for (String value : values) if (value != null && !value.trim().isEmpty()) return value.trim();
        return "";
    }

    private int dp(int value) {
        return Math.round(value * getResources().getDisplayMetrics().density);
    }

    @Override protected void onAttachedToWindow() {
        super.onAttachedToWindow();
        detached = false;
    }

    @Override protected void onDetachedFromWindow() {
        detached = true;
        for (RequestHandle request : new ArrayList<>(requests)) request.cancel();
        requests.clear();
        super.onDetachedFromWindow();
    }

    private enum State { PENDING, UPLOADING, READY, FAILED }

    private static final class AttachmentItem {
        final Uri uri;
        final String name;
        final String mime;
        final long size;
        State state = State.PENDING;
        String status = "等待上传";
        JsonObject value;
        RequestHandle request;

        AttachmentItem(Uri uri, String name, String mime, long size) {
            this.uri = uri;
            this.name = name;
            this.mime = mime;
            this.size = size;
        }
    }
}
