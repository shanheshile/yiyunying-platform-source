package xyz.jjmxg.yiyunying.ui.chat;

import android.content.Context;
import android.content.Intent;
import android.database.Cursor;
import android.net.Uri;
import android.os.Bundle;
import android.provider.OpenableColumns;
import android.view.Gravity;
import android.view.MenuItem;
import android.view.View;
import android.view.ViewGroup;
import android.widget.EditText;
import android.widget.FrameLayout;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.TextView;

import androidx.activity.result.ActivityResultLauncher;
import androidx.activity.result.ActivityResult;
import androidx.activity.result.contract.ActivityResultContracts;
import androidx.annotation.NonNull;
import androidx.recyclerview.widget.LinearLayoutManager;
import androidx.recyclerview.widget.RecyclerView;

import com.google.android.material.appbar.MaterialToolbar;
import com.google.android.material.button.MaterialButton;
import com.google.android.material.card.MaterialCardView;
import xyz.jjmxg.yiyunying.ui.common.YiyunyingDialogBuilder;
import com.google.android.material.progressindicator.LinearProgressIndicator;
import com.google.android.material.snackbar.Snackbar;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;

import java.util.ArrayDeque;
import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Locale;
import java.util.Map;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.core.AppAccess;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.data.api.RequestHandle;
import xyz.jjmxg.yiyunying.ui.common.ImageLoader;
import xyz.jjmxg.yiyunying.ui.common.ActionIconResolver;
import xyz.jjmxg.yiyunying.ui.common.SystemInsetActivity;
import xyz.jjmxg.yiyunying.ui.upload.ContentUriRequestBody;
import xyz.jjmxg.yiyunying.ui.upload.MediaPickerActivity;
import xyz.jjmxg.yiyunying.ui.upload.UploadPolicyStore;

public final class GroupAlbumDetailActivity extends SystemInsetActivity {
    private static final String EXTRA_ROOM_ID = "room_id";
    private static final String EXTRA_ALBUM_ID = "album_id";
    private static final String EXTRA_TITLE = "title";

    private final List<JsonObject> allItems = new ArrayList<>();
    private final List<JsonObject> visibleItems = new ArrayList<>();
    private final ArrayDeque<Uri> uploadQueue = new ArrayDeque<>();
    private LinearProgressIndicator progress;
    private TextView empty;
    private MaterialToolbar toolbar;
    private AlbumMediaAdapter adapter;
    private RequestHandle roomRequest;
    private RequestHandle request;
    private RequestHandle actionRequest;
    private RequestHandle uploadRequest;
    private long roomId;
    private long albumId;
    private String roomKind = "group";
    private String query = "";

    private final ActivityResultLauncher<Intent> picker = registerForActivityResult(
        new ActivityResultContracts.StartActivityForResult(), this::selectedFiles);

    public static void open(Context context, long roomId, long albumId, String title) {
        context.startActivity(new Intent(context, GroupAlbumDetailActivity.class)
            .putExtra(EXTRA_ROOM_ID, roomId)
            .putExtra(EXTRA_ALBUM_ID, albumId)
            .putExtra(EXTRA_TITLE, title));
    }

    @Override protected void onCreate(Bundle state) {
        super.onCreate(state);
        roomId = getIntent().getLongExtra(EXTRA_ROOM_ID, 0);
        albumId = getIntent().getLongExtra(EXTRA_ALBUM_ID, 0);
        if (roomId <= 0 || albumId <= 0) { finish(); return; }

        LinearLayout root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setBackgroundColor(getColor(R.color.surface));
        toolbar = new MaterialToolbar(this);
        String albumTitle = getIntent().getStringExtra(EXTRA_TITLE);
        toolbar.setTitle(albumTitle == null || albumTitle.trim().isEmpty() ? "相册详情" : albumTitle);
        toolbar.setSubtitle("相册");
        toolbar.setNavigationIcon(R.drawable.ic_back);
        toolbar.setNavigationOnClickListener(view -> finish());
        MenuItem search = toolbar.getMenu().add("搜索");
        search.setIcon(R.drawable.ic_search);
        search.setShowAsAction(MenuItem.SHOW_AS_ACTION_ALWAYS);
        MenuItem upload = toolbar.getMenu().add("上传");
        upload.setIcon(R.drawable.ic_add);
        upload.setShowAsAction(MenuItem.SHOW_AS_ACTION_ALWAYS);
        toolbar.setOnMenuItemClickListener(item -> {
            if (item == search) showSearch(); else picker.launch(MediaPickerActivity.intent(this, true));
            return true;
        });
        root.addView(toolbar, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, dp(56)));

        progress = new LinearProgressIndicator(this);
        progress.setVisibility(View.INVISIBLE);
        root.addView(progress, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, dp(4)));
        FrameLayout body = new FrameLayout(this);
        RecyclerView recycler = new RecyclerView(this);
        recycler.setLayoutManager(new LinearLayoutManager(this));
        recycler.setClipToPadding(false);
        recycler.setPadding(dp(12), dp(8), dp(12), dp(24));
        adapter = new AlbumMediaAdapter();
        recycler.setAdapter(adapter);
        body.addView(recycler, new FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT));
        empty = new TextView(this);
        empty.setText("相册内还没有图片或视频\n点击右上角上传");
        empty.setTextColor(getColor(R.color.on_surface_variant));
        empty.setTextSize(15);
        empty.setGravity(Gravity.CENTER);
        body.addView(empty, new FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT));
        root.addView(body, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, 0, 1f));
        setContentView(root);
        loadRoomKind();
        load();
    }

    private void loadRoomKind() {
        if (roomRequest != null) roomRequest.cancel();
        roomRequest = AppAccess.from(this).repository().get(base(), new LinkedHashMap<>(), result -> {
            roomRequest = null;
            if (isFinishing() || isDestroyed() || toolbar == null || !result.isSuccessful()) return;
            JsonObject room = Jsons.object(result.dataObject(), "room");
            roomKind = "chat_room".equals(Jsons.string(room, "room_kind")) ? "chat_room" : "group";
            toolbar.setSubtitle(spacePrefix() + "相册");
            if (adapter != null) adapter.notifyDataSetChanged();
        });
    }

    private void load() {
        if (request != null) request.cancel();
        progress.setVisibility(View.VISIBLE);
        request = AppAccess.from(this).repository().get(base() + "/albums", new LinkedHashMap<>(), result -> {
            request = null;
            if (isFinishing() || isDestroyed()) return;
            progress.setVisibility(View.INVISIBLE);
            if (!result.isSuccessful()) {
                message(result.message().isEmpty() ? spacePrefix() + "相册加载失败" : result.message());
                return;
            }
            allItems.clear();
            for (JsonObject album : result.objectItems()) {
                if (Jsons.longValue(album, "id") != albumId) continue;
                for (JsonElement value : Jsons.array(album, "photos")) {
                    if (!value.isJsonObject()) continue;
                    JsonObject media = value.getAsJsonObject().deepCopy();
                    media.addProperty("url", Jsons.string(media, "image_url"));
                    media.addProperty("file_name", Jsons.string(media, "caption"));
                    allItems.add(media);
                }
                break;
            }
            filter();
        });
    }

    private void showSearch() {
        EditText input = new EditText(this);
        input.setHint("搜索说明、上传者或日期");
        input.setSingleLine(true);
        input.setText(query);
        input.setSelection(input.length());
        int padding = dp(20);
        FrameLayout holder = new FrameLayout(this);
        holder.setPadding(padding, 0, padding, 0);
        holder.addView(input, new FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));
        new YiyunyingDialogBuilder(this)
            .setTitle("搜索" + spacePrefix() + "相册")
            .setView(holder)
            .setPositiveButton("搜索", (dialog, which) -> {
                query = input.getText() == null ? "" : input.getText().toString().trim();
                filter();
            })
            .setNeutralButton("清除", (dialog, which) -> { query = ""; filter(); })
            .setNegativeButton("取消", null)
            .show();
    }

    private void filter() {
        visibleItems.clear();
        String needle = query.toLowerCase(Locale.ROOT);
        for (JsonObject item : allItems) {
            String text = (Jsons.string(item, "caption") + " "
                + Jsons.string(item, "uploader_nickname") + " "
                + Jsons.string(item, "uploader_account") + " "
                + Jsons.string(item, "created_at")).toLowerCase(Locale.ROOT);
            if (needle.isEmpty() || text.contains(needle)) visibleItems.add(item);
        }
        adapter.notifyDataSetChanged();
        empty.setVisibility(visibleItems.isEmpty() ? View.VISIBLE : View.GONE);
        empty.setText(allItems.isEmpty() ? "相册内还没有图片或视频\n点击右上角上传" : "没有找到符合条件的媒体");
    }

    private void selectedFiles(ActivityResult result) {
        if (result.getResultCode() != RESULT_OK || result.getData() == null) return;
        ArrayList<Uri> uris = result.getData()
            .getParcelableArrayListExtra(MediaPickerActivity.EXTRA_SELECTED_URIS);
        if (uris == null || uris.isEmpty() || uploadRequest != null) return;
        uploadQueue.clear();
        uploadQueue.addAll(uris);
        uploadNext();
    }

    private void uploadNext() {
        if (uploadRequest != null || actionRequest != null) return;
        Uri uri = uploadQueue.pollFirst();
        if (uri == null) { progress.setVisibility(View.INVISIBLE); load(); return; }
        String name = spacePrefix() + "相册媒体";
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
        if (mime == null) mime = "application/octet-stream";
        String mediaType = mime.startsWith("video/") ? "video" : "image";
        if (!UploadPolicyStore.accepts(this, mediaType, size)) {
            message(UploadPolicyStore.rejectionMessage(this, mediaType, size));
            uploadNext();
            return;
        }
        progress.setVisibility(View.VISIBLE);
        String fileName = name;
        String fileMime = mime;
        long fileSize = size;
        Map<String, String> fields = new LinkedHashMap<>();
        fields.put("scene", spacePrefix() + "相册");
        uploadRequest = AppAccess.from(this).repository().upload(
            "/api/user/uploads", name, mime,
            new ContentUriRequestBody(getContentResolver(), uri, mime, size), fields, result -> {
                uploadRequest = null;
                if (isFinishing() || isDestroyed()) return;
                if (!result.isSuccessful()) {
                    message(result.message().isEmpty() ? "媒体上传失败" : result.message());
                    uploadNext();
                    return;
                }
                JsonObject payload = new JsonObject();
                payload.addProperty("image_url", Jsons.string(result.dataObject(), "file_url"));
                payload.addProperty("thumbnail_url", Jsons.string(result.dataObject(), "thumbnail_url"));
                payload.addProperty("media_type", fileMime.startsWith("video/") ? "video" : "image");
                payload.addProperty("mime_type", fileMime);
                payload.addProperty("size_bytes", fileSize);
                payload.addProperty("caption", fileName);
                actionRequest = AppAccess.from(this).repository().post(
                    base() + "/albums/" + albumId + "/photos", payload, saved -> {
                        actionRequest = null;
                        if (isFinishing() || isDestroyed()) return;
                        if (!saved.isSuccessful()) message(saved.message().isEmpty() ? "相册记录保存失败" : saved.message());
                        uploadNext();
                    });
            });
    }

    private void preview(int selected) {
        if (isFinishing() || isDestroyed() || selected < 0 || selected >= visibleItems.size()) return;
        InlineMediaPreviewDialog.show(this, visibleItems, selected);
    }

    private void delete(JsonObject item) {
        long photoId = Jsons.longValue(item, "id");
        new YiyunyingDialogBuilder(this)
            .setTitle("删除相册媒体")
            .setMessage("删除后" + entityLabel() + "内会保留操作通知，确定继续吗？")
            .setPositiveButton("删除", (dialog, which) -> {
                progress.setVisibility(View.VISIBLE);
                actionRequest = AppAccess.from(this).repository().delete(
                    base() + "/albums/" + albumId + "/photos/" + photoId, new JsonObject(), result -> {
                        actionRequest = null;
                        if (isFinishing() || isDestroyed()) return;
                        if (!result.isSuccessful()) message(result.message().isEmpty() ? "删除失败" : result.message());
                        else load();
                    });
            })
            .setNegativeButton("取消", null)
            .show();
    }

    private String base() { return "/api/user/chat-rooms/" + roomId; }

    private boolean isChatRoom() { return "chat_room".equals(roomKind); }

    private String entityLabel() { return isChatRoom() ? "聊天室" : "群聊"; }

    private String spacePrefix() { return isChatRoom() ? "聊天室" : "群"; }

    private void message(String text) {
        if (isFinishing() || isDestroyed() || progress == null) return;
        Snackbar.make(progress, text == null || text.isEmpty() ? "操作未完成" : text, Snackbar.LENGTH_LONG).show();
    }

    private String sizeText(long bytes) {
        if (bytes >= 1073741824L) return String.format(Locale.CHINA, "%.2f GB", bytes / 1073741824d);
        if (bytes >= 1048576L) return String.format(Locale.CHINA, "%.2f MB", bytes / 1048576d);
        if (bytes >= 1024L) return String.format(Locale.CHINA, "%.1f KB", bytes / 1024d);
        return Math.max(0, bytes) + " B";
    }

    private int dp(int value) { return Math.round(value * getResources().getDisplayMetrics().density); }

    @Override protected void onDestroy() {
        if (roomRequest != null) roomRequest.cancel();
        if (request != null) request.cancel();
        if (actionRequest != null) actionRequest.cancel();
        if (uploadRequest != null) uploadRequest.cancel();
        super.onDestroy();
    }

    private final class AlbumMediaAdapter extends RecyclerView.Adapter<AlbumMediaAdapter.Holder> {
        @NonNull @Override public Holder onCreateViewHolder(@NonNull ViewGroup parent, int type) {
            MaterialCardView card = new MaterialCardView(parent.getContext());
            card.setRadius(dp(7));
            card.setCardElevation(0);
            card.setStrokeWidth(dp(1));
            card.setStrokeColor(getColor(R.color.outline));
            RecyclerView.LayoutParams cardParams = new RecyclerView.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT);
            cardParams.setMargins(0, dp(5), 0, dp(5));
            card.setLayoutParams(cardParams);
            LinearLayout row = new LinearLayout(parent.getContext());
            row.setOrientation(LinearLayout.HORIZONTAL);
            row.setGravity(Gravity.CENTER_VERTICAL);
            row.setPadding(dp(8), dp(8), dp(8), dp(8));
            ImageView image = new ImageView(parent.getContext());
            image.setScaleType(ImageView.ScaleType.CENTER_CROP);
            image.setBackgroundColor(getColor(R.color.surface_container));
            row.addView(image, new LinearLayout.LayoutParams(dp(108), dp(86)));
            LinearLayout details = new LinearLayout(parent.getContext());
            details.setOrientation(LinearLayout.VERTICAL);
            details.setPadding(dp(12), 0, 0, 0);
            TextView title = new TextView(parent.getContext());
            title.setTextColor(getColor(R.color.on_surface));
            title.setTextSize(15);
            title.setMaxLines(2);
            TextView meta = new TextView(parent.getContext());
            meta.setTextColor(getColor(R.color.on_surface_variant));
            meta.setTextSize(12);
            meta.setPadding(0, dp(5), 0, 0);
            LinearLayout actions = new LinearLayout(parent.getContext());
            actions.setGravity(Gravity.END);
            MaterialButton preview = new MaterialButton(parent.getContext());
            preview.setText("预览");
            preview.setMinWidth(0);
            MaterialButton remove = new MaterialButton(parent.getContext());
            remove.setText("删除");
            remove.setMinWidth(0);
            ActionIconResolver.apply(remove, "删除这项相册媒体", 0);
            actions.addView(preview);
            actions.addView(remove);
            details.addView(title);
            details.addView(meta);
            details.addView(actions, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));
            row.addView(details, new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f));
            card.addView(row);
            return new Holder(card, image, title, meta, preview, remove);
        }

        @Override public void onBindViewHolder(@NonNull Holder holder, int position) {
            JsonObject item = visibleItems.get(position);
            String previewUrl = Jsons.string(item, "thumbnail_url");
            if (previewUrl.isEmpty()) previewUrl = Jsons.string(item, "image_url");
            ImageLoader.get().load(ImageLoader.get().absoluteUrl(GroupAlbumDetailActivity.this, previewUrl), holder.image, R.drawable.ic_file);
            String caption = Jsons.string(item, "caption");
            holder.title.setText(caption.isEmpty()
                ? ("video".equals(Jsons.string(item, "media_type")) ? spacePrefix() + "视频" : spacePrefix() + "图片")
                : caption);
            String uploader = Jsons.string(item, "uploader_nickname");
            if (uploader.isEmpty()) uploader = Jsons.string(item, "uploader_account");
            holder.meta.setText("上传者：" + (uploader.isEmpty() ? "未知用户" : uploader)
                + "\n上传时间：" + Jsons.string(item, "created_at")
                + "\n大小：" + sizeText(Jsons.longValue(item, "size_bytes"))
                + " · 下载 " + Jsons.longValue(item, "download_count") + " 次");
            holder.remove.setVisibility(item.has("can_delete") && item.get("can_delete").getAsBoolean() ? View.VISIBLE : View.GONE);
            holder.preview.setOnClickListener(view -> {
                int current = holder.getBindingAdapterPosition();
                if (current >= 0 && current < visibleItems.size()) preview(current);
            });
            holder.remove.setOnClickListener(view -> delete(item));
            holder.itemView.setOnClickListener(view -> {
                int current = holder.getBindingAdapterPosition();
                if (current >= 0 && current < visibleItems.size()) preview(current);
            });
        }

        @Override public int getItemCount() { return visibleItems.size(); }

        final class Holder extends RecyclerView.ViewHolder {
            final ImageView image;
            final TextView title;
            final TextView meta;
            final MaterialButton preview;
            final MaterialButton remove;
            Holder(View root, ImageView image, TextView title, TextView meta, MaterialButton preview, MaterialButton remove) {
                super(root); this.image = image; this.title = title; this.meta = meta; this.preview = preview; this.remove = remove;
            }
        }
    }
}
