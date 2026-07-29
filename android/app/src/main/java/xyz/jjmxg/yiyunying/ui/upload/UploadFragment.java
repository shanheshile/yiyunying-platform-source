package xyz.jjmxg.yiyunying.ui.upload;

import android.content.Intent;
import android.database.Cursor;
import android.net.Uri;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.provider.OpenableColumns;
import android.text.Editable;
import android.text.TextWatcher;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.view.inputmethod.EditorInfo;

import androidx.activity.result.ActivityResultLauncher;
import androidx.activity.result.ActivityResult;
import androidx.activity.result.contract.ActivityResultContracts;
import androidx.annotation.NonNull;
import androidx.annotation.Nullable;
import androidx.recyclerview.widget.LinearLayoutManager;
import androidx.recyclerview.widget.RecyclerView;

import xyz.jjmxg.yiyunying.ui.common.YiyunyingDialogBuilder;
import com.google.android.material.snackbar.Snackbar;
import com.google.gson.JsonObject;

import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.LinkedHashSet;
import java.util.Set;
import java.util.Locale;
import java.util.Map;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.data.api.RequestHandle;
import xyz.jjmxg.yiyunying.databinding.FragmentUploadBinding;
import xyz.jjmxg.yiyunying.databinding.ItemRecordBinding;
import xyz.jjmxg.yiyunying.domain.Role;
import xyz.jjmxg.yiyunying.ui.common.BaseFragment;
import xyz.jjmxg.yiyunying.ui.common.DisplayText;
import xyz.jjmxg.yiyunying.ui.common.RecordDetailDialog;

public final class UploadFragment extends BaseFragment {
    private final Handler handler = new Handler(Looper.getMainLooper());
    private final Runnable delayedLoad = this::load;
    private FragmentUploadBinding binding;
    private UploadAdapter adapter;
    private Uri selectedUri;
    private String selectedName = "";
    private String selectedMime = "application/octet-stream";
    private long selectedSize = -1;
    private String category = "";
    private RequestHandle upload;
    private RequestHandle listRequest;
    private RequestHandle deleteRequest;
    private RequestHandle favoriteRequest;
    private final Set<Long> selectedIds = new LinkedHashSet<>();
    private boolean selectionMode;

    private final ActivityResultLauncher<Intent> picker = registerForActivityResult(
        new ActivityResultContracts.StartActivityForResult(), this::selected);

    public static UploadFragment newInstance() { return new UploadFragment(); }

    @Nullable
    @Override public View onCreateView(@NonNull LayoutInflater inflater, @Nullable ViewGroup container, @Nullable Bundle state) {
        binding = FragmentUploadBinding.inflate(inflater, container, false);
        Role role = app().session().role();
        host().setPageTitle(role == Role.USER ? "我的上传" : "上传文件管理");
        binding.uploadSection.setVisibility(View.VISIBLE);
        binding.selectButton.setOnClickListener(view ->
            picker.launch(FilePickerActivity.pickerIntent(requireContext(), 1)));
        binding.uploadButton.setOnClickListener(view -> upload());
        binding.downloadCenterButton.setOnClickListener(view -> DownloadsActivity.open(requireContext()));
        binding.swipeRefresh.setOnRefreshListener(this::load);
        adapter = new UploadAdapter(new UploadAdapter.Listener() {
            @Override public void onOpen(JsonObject item) { FilePreviewActivity.open(requireContext(), item); }
            @Override public void onActions(View anchor, JsonObject item) { showActions(item); }
            @Override public void onLongPress(JsonObject item) { setSelected(item, true); }
            @Override public void onSelection(JsonObject item, boolean selected) { setSelected(item, selected); }
        });
        binding.recycler.setLayoutManager(new LinearLayoutManager(requireContext()));
        binding.recycler.setAdapter(adapter);
        configureFilters();
        binding.batchCancel.setOnClickListener(view -> exitSelection());
        binding.batchDelete.setOnClickListener(view -> confirmBatchDelete());
        load();
        return binding.getRoot();
    }

    private void configureFilters() {
        binding.categoryChips.setOnCheckedStateChangeListener((group, checkedIds) -> {
            if (checkedIds.isEmpty()) return;
            int id = checkedIds.get(0);
            if (id == R.id.filterImage) category = "image";
            else if (id == R.id.filterVideo) category = "video";
            else if (id == R.id.filterAudio) category = "audio";
            else if (id == R.id.filterDocument) category = "document";
            else if (id == R.id.filterArchive) category = "archive";
            else if (id == R.id.filterOther) category = "other";
            else category = "";
            load();
        });
        binding.searchInput.setOnEditorActionListener((view, actionId, event) -> {
            if (actionId == EditorInfo.IME_ACTION_SEARCH) { load(); return true; }
            return false;
        });
        binding.searchInput.addTextChangedListener(new TextWatcher() {
            @Override public void beforeTextChanged(CharSequence value, int start, int count, int after) { }
            @Override public void onTextChanged(CharSequence value, int start, int before, int count) {
                handler.removeCallbacks(delayedLoad);
                handler.postDelayed(delayedLoad, 450L);
            }
            @Override public void afterTextChanged(Editable value) { }
        });
        View.OnFocusChangeListener dates = (view, focused) -> { if (!focused) load(); };
        binding.dateFromInput.setOnFocusChangeListener(dates);
        binding.dateToInput.setOnFocusChangeListener(dates);
    }

    private void selected(ActivityResult result) {
        if (result.getResultCode() != android.app.Activity.RESULT_OK || result.getData() == null) return;
        ArrayList<String> values = result.getData()
            .getStringArrayListExtra(FilePickerActivity.EXTRA_SELECTED_URIS);
        Uri uri = values == null || values.isEmpty() ? null : Uri.parse(values.get(0));
        if (uri == null || binding == null) return;
        selectedUri = uri;
        selectedName = "本地文件";
        selectedSize = -1;
        try { requireContext().getContentResolver().takePersistableUriPermission(uri, android.content.Intent.FLAG_GRANT_READ_URI_PERMISSION); }
        catch (RuntimeException ignored) { }
        try (Cursor cursor = requireContext().getContentResolver().query(uri, null, null, null, null)) {
            if (cursor != null && cursor.moveToFirst()) {
                int nameIndex = cursor.getColumnIndex(OpenableColumns.DISPLAY_NAME);
                int sizeIndex = cursor.getColumnIndex(OpenableColumns.SIZE);
                if (nameIndex >= 0 && !cursor.isNull(nameIndex)) selectedName = cursor.getString(nameIndex);
                if (sizeIndex >= 0 && !cursor.isNull(sizeIndex)) selectedSize = cursor.getLong(sizeIndex);
            }
        }
        String mime = requireContext().getContentResolver().getType(uri);
        selectedMime = mime == null ? "application/octet-stream" : mime;
        String mediaType = selectedMime.startsWith("image/") ? "image"
            : (selectedMime.startsWith("video/") ? "video" : (selectedMime.startsWith("audio/") ? "audio" : "file"));
        binding.fileName.setText(selectedName);
        binding.fileMeta.setText(chineseType(selectedMime) + " · " + sizeText(selectedSize)
            + " · 上限 " + UploadPolicyStore.format(UploadPolicyStore.maxBytes(requireContext(), mediaType)));
        boolean valid = UploadPolicyStore.accepts(requireContext(), mediaType, selectedSize);
        binding.uploadButton.setEnabled(valid);
        if (!valid) message(binding.getRoot(), UploadPolicyStore.rejectionMessage(requireContext(), mediaType, selectedSize));
    }

    private void upload() {
        if (selectedUri == null || upload != null) return;
        String scene = text(binding.sceneInput);
        if (scene.isEmpty()) scene = "通用上传";
        ContentUriRequestBody body = new ContentUriRequestBody(requireContext().getContentResolver(), selectedUri, selectedMime, selectedSize);
        Map<String, String> fields = new LinkedHashMap<>();
        fields.put("scene", scene);
        binding.progress.setVisibility(View.VISIBLE);
        binding.uploadButton.setEnabled(false);
        upload = app().repository().upload(uploadPath(), selectedName, selectedMime, body, fields, result -> {
            upload = null;
            if (binding == null) return;
            binding.progress.setVisibility(View.INVISIBLE);
            if (handleFailure(result, binding.getRoot())) { binding.uploadButton.setEnabled(true); return; }
            selectedUri = null;
            binding.fileName.setText("尚未选择文件");
            binding.fileMeta.setText("可从手机相册或文件管理器选择");
            binding.uploadButton.setEnabled(false);
            message(binding.getRoot(), result.message().isEmpty() ? "文件上传成功" : result.message());
            load();
        });
        track(upload);
    }

    private void load() {
        handler.removeCallbacks(delayedLoad);
        if (binding == null) return;
        if (listRequest != null) listRequest.cancel();
        binding.progress.setVisibility(View.VISIBLE);
        Map<String, String> query = new LinkedHashMap<>();
        query.put("limit", "100");
        put(query, "keyword", text(binding.searchInput));
        put(query, "category", category);
        put(query, "date_from", text(binding.dateFromInput));
        put(query, "date_to", text(binding.dateToInput));
        listRequest = app().repository().get(listPath(), query, result -> {
            listRequest = null;
            if (binding == null) return;
            binding.progress.setVisibility(View.INVISIBLE);
            binding.swipeRefresh.setRefreshing(false);
            if (handleFailure(result, binding.getRoot())) return;
            List<JsonObject> items = result.objectItems();
            adapter.submit(items);
            binding.emptyText.setVisibility(items.isEmpty() ? View.VISIBLE : View.GONE);
        });
        track(listRequest);
    }

    private void showActions(JsonObject item) {
        boolean user = app().session().role() == Role.USER;
        boolean favorited = flag(item, "favorited");
        String[] actions = user
            ? new String[]{"预览文件", "查看详细信息", favorited ? "取消收藏" : "收藏文件", "删除文件"}
            : new String[]{"预览文件", "查看详细信息", "删除文件"};
        new YiyunyingDialogBuilder(requireContext()).setBusinessTitle(Jsons.string(item, "original_name"))
            .setItems(actions, (dialog, which) -> {
                if (which == 0) FilePreviewActivity.open(requireContext(), item);
                else if (which == 1) RecordDetailDialog.show(requireContext(), "上传文件信息", item);
                else if (user && which == 2) toggleFavorite(item);
                else confirmDelete(item);
            }).setNegativeButton("取消", null).show();
    }

    private void toggleFavorite(JsonObject item) {
        if (favoriteRequest != null || binding == null) return;
        long id = Jsons.longValue(item, "id");
        if (id <= 0) {
            Snackbar.make(binding.getRoot(), "文件信息不完整，暂时无法收藏", Snackbar.LENGTH_LONG).show();
            return;
        }
        favoriteRequest = app().repository().post("/api/user/uploads/" + id + "/favorite", new JsonObject(), result -> {
            favoriteRequest = null;
            if (binding == null) return;
            if (handleFailure(result, binding.getRoot())) return;
            boolean active = flag(result.dataObject(), "favorited");
            item.addProperty("favorited", active);
            Snackbar.make(binding.getRoot(), active ? "文件已收藏" : "已取消收藏文件", Snackbar.LENGTH_SHORT).show();
            load();
        });
        track(favoriteRequest);
    }

    private void confirmDelete(JsonObject item) {
        new YiyunyingDialogBuilder(requireContext())
            .setTitle("删除上传文件")
            .setMessage("确认删除“" + Jsons.string(item, "original_name") + "”？删除后相关消息或内容中的附件可能无法继续打开。")
            .setPositiveButton("删除", (dialog, which) -> delete(item))
            .setNegativeButton("取消", null).show();
    }

    private void delete(JsonObject item) {
        if (deleteRequest != null) return;
        binding.progress.setVisibility(View.VISIBLE);
        deleteRequest = app().repository().delete(deletePath(Jsons.longValue(item, "id")), new JsonObject(), result -> {
            deleteRequest = null;
            if (binding == null) return;
            binding.progress.setVisibility(View.INVISIBLE);
            if (handleFailure(result, binding.getRoot())) return;
            Snackbar.make(binding.getRoot(), "文件已删除", Snackbar.LENGTH_SHORT).show();
            load();
        });
        track(deleteRequest);
    }

    private void setSelected(JsonObject item, boolean selected) {
        selectionMode = true;
        long id = Jsons.longValue(item, "id");
        if (selected) selectedIds.add(id); else selectedIds.remove(id);
        if (selectedIds.isEmpty()) exitSelection(); else {
            binding.batchBar.setVisibility(View.VISIBLE);
            binding.batchCount.setText("已选择 " + selectedIds.size() + " 个文件");
            adapter.setSelection(true, selectedIds);
        }
    }

    private void exitSelection() {
        selectionMode = false; selectedIds.clear();
        if (binding != null) binding.batchBar.setVisibility(View.GONE);
        if (adapter != null) adapter.setSelection(false, selectedIds);
    }

    private void confirmBatchDelete() {
        new YiyunyingDialogBuilder(requireContext()).setTitle("批量删除上传文件")
            .setMessage("将删除选中的 " + selectedIds.size() + " 个文件。共享物理文件仍被其他内容引用时不会误删。")
            .setPositiveButton("删除", (dialog, which) -> deleteBatch(new ArrayList<>(selectedIds), 0, 0))
            .setNegativeButton("取消", null).show();
    }

    private void deleteBatch(List<Long> ids, int index, int success) {
        if (index >= ids.size()) {
            Snackbar.make(binding.getRoot(), "已删除 " + success + " 个文件", Snackbar.LENGTH_LONG).show();
            exitSelection(); load(); return;
        }
        app().repository().delete(deletePath(ids.get(index)), new JsonObject(), result ->
            deleteBatch(ids, index + 1, success + (result.isSuccessful() ? 1 : 0)));
    }

    private String listPath() {
        Role role = app().session().role();
        long appId = app().session().selectedAppId();
        if (role == Role.PLATFORM) return "/api/platform/apps/" + appId + "/uploads";
        if (role == Role.ADMIN) return "/api/admin/apps/" + appId + "/uploads";
        return "/api/user/uploads";
    }

    private String uploadPath() {
        Role role = app().session().role();
        long appId = app().session().selectedAppId();
        if (role == Role.PLATFORM) return "/api/platform/apps/" + appId + "/uploads";
        if (role == Role.ADMIN) return "/api/admin/apps/" + appId + "/uploads";
        return "/api/user/uploads";
    }

    private String deletePath(long id) { return listPath() + "/" + id; }
    private static void put(Map<String, String> query, String key, String value) { if (value != null && !value.isEmpty()) query.put(key, value); }
    private static String text(android.widget.EditText input) { return input.getText() == null ? "" : input.getText().toString().trim(); }
    private static boolean flag(JsonObject item, String key) {
        return item != null && item.has(key) && !item.get(key).isJsonNull() && item.get(key).getAsBoolean();
    }

    private String chineseType(String mime) {
        if (mime.startsWith("image/")) return "图片";
        if (mime.startsWith("video/")) return "视频";
        if (mime.startsWith("audio/")) return "音频";
        if (mime.startsWith("text/") || mime.contains("pdf") || mime.contains("document")) return "文档";
        return "文件";
    }

    private String sizeText(long bytes) {
        if (bytes < 0) return "大小未知";
        if (bytes < 1024) return bytes + " B";
        if (bytes < 1024 * 1024) return String.format(Locale.CHINA, "%.1f KB", bytes / 1024f);
        return String.format(Locale.CHINA, "%.1f MB", bytes / 1024f / 1024f);
    }

    @Override public void onDestroyView() {
        handler.removeCallbacks(delayedLoad);
        binding = null;
        super.onDestroyView();
    }

    private static final class UploadAdapter extends RecyclerView.Adapter<UploadAdapter.Holder> {
        interface Listener { void onOpen(JsonObject item); void onActions(View anchor, JsonObject item); void onLongPress(JsonObject item); void onSelection(JsonObject item, boolean selected); }
        private final Listener listener;
        private final List<JsonObject> items = new ArrayList<>();
        private final Set<Long> selected = new LinkedHashSet<>();
        private boolean selectionMode;
        UploadAdapter(Listener listener) { this.listener = listener; setHasStableIds(true); }
        void submit(List<JsonObject> next) { items.clear(); items.addAll(next); notifyDataSetChanged(); }
        void setSelection(boolean enabled, Set<Long> values) { selectionMode = enabled; selected.clear(); selected.addAll(values); notifyDataSetChanged(); }
        @Override public long getItemId(int position) { return Jsons.longValue(items.get(position), "id"); }
        @NonNull @Override public Holder onCreateViewHolder(@NonNull ViewGroup parent, int type) {
            return new Holder(ItemRecordBinding.inflate(LayoutInflater.from(parent.getContext()), parent, false));
        }
        @Override public void onBindViewHolder(@NonNull Holder holder, int position) {
            JsonObject item = items.get(position);
            String category = Jsons.string(item, "file_category_name");
            holder.binding.avatar.setText(category.isEmpty() ? "文" : category.substring(0, 1));
            holder.binding.title.setText(Jsons.string(item, "original_name"));
            String owner = Jsons.string(item, "user_nickname");
            if (owner.isEmpty()) owner = Jsons.string(item, "user_account");
            holder.binding.subtitle.setText(category + " · " + Jsons.string(item, "scene")
                + (owner.isEmpty() ? "" : " · 上传者 " + owner));
            holder.binding.metadata.setText(size(Jsons.longValue(item, "size_bytes")) + " · " + Jsons.string(item, "created_at"));
            holder.binding.moreButton.setVisibility(View.VISIBLE);
            long id = Jsons.longValue(item, "id");
            holder.binding.selectionCheck.setVisibility(selectionMode ? View.VISIBLE : View.GONE);
            holder.binding.selectionCheck.setChecked(selected.contains(id));
            holder.binding.selectionCheck.setOnClickListener(view -> listener.onSelection(item, holder.binding.selectionCheck.isChecked()));
            holder.binding.getRoot().setOnClickListener(view -> { if (selectionMode) listener.onSelection(item, !selected.contains(id)); else listener.onOpen(item); });
            holder.binding.getRoot().setOnLongClickListener(view -> { listener.onLongPress(item); return true; });
            holder.binding.moreButton.setOnClickListener(view -> listener.onActions(view, item));
        }
        @Override public int getItemCount() { return items.size(); }
        private static String size(long bytes) {
            if (bytes < 1024) return bytes + " B";
            if (bytes < 1024L * 1024L) return String.format(Locale.CHINA, "%.1f KB", bytes / 1024f);
            return String.format(Locale.CHINA, "%.1f MB", bytes / 1024f / 1024f);
        }
        static final class Holder extends RecyclerView.ViewHolder {
            final ItemRecordBinding binding;
            Holder(ItemRecordBinding binding) { super(binding.getRoot()); this.binding = binding; }
        }
    }
}
