package xyz.jjmxg.yiyunying.ui.module;

import android.app.Activity;
import android.content.Context;
import android.content.Intent;
import android.database.Cursor;
import android.net.Uri;
import android.os.Bundle;
import android.provider.OpenableColumns;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.view.inputmethod.EditorInfo;

import androidx.annotation.NonNull;
import androidx.annotation.Nullable;
import androidx.activity.result.ActivityResultLauncher;
import androidx.activity.result.contract.ActivityResultContracts;
import androidx.appcompat.widget.PopupMenu;
import androidx.recyclerview.widget.LinearLayoutManager;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.ui.common.YiyunyingDialogBuilder;
import com.google.gson.JsonArray;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;

import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.LinkedHashSet;
import java.util.Set;
import java.util.UUID;

import xyz.jjmxg.yiyunying.data.api.AuthMode;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.databinding.FragmentModuleListBinding;
import xyz.jjmxg.yiyunying.domain.Role;
import xyz.jjmxg.yiyunying.domain.module.ActionSpec;
import xyz.jjmxg.yiyunying.domain.module.ModuleSpec;
import xyz.jjmxg.yiyunying.domain.module.PathResolver;
import xyz.jjmxg.yiyunying.ui.common.BaseFragment;
import xyz.jjmxg.yiyunying.ui.common.DynamicFormDialog;
import xyz.jjmxg.yiyunying.ui.common.RecordAdapter;
import xyz.jjmxg.yiyunying.ui.common.RecordDetailDialog;
import xyz.jjmxg.yiyunying.ui.common.ContentReportDialog;
import xyz.jjmxg.yiyunying.ui.common.GlassActionDialog;
import xyz.jjmxg.yiyunying.ui.common.EntitlementEditorDialog;
import xyz.jjmxg.yiyunying.ui.chat.ChatActivity;
import xyz.jjmxg.yiyunying.ui.common.UiGuard;
import xyz.jjmxg.yiyunying.ui.management.ManagedUsersActivity;
import xyz.jjmxg.yiyunying.ui.permission.RolePermissionActivity;
import xyz.jjmxg.yiyunying.ui.profile.UserProfileActivity;
import xyz.jjmxg.yiyunying.ui.bounty.BountyPublishActivity;
import xyz.jjmxg.yiyunying.ui.upload.ContentUriRequestBody;
import xyz.jjmxg.yiyunying.ui.upload.MediaPickerActivity;
import xyz.jjmxg.yiyunying.ui.upload.UploadPolicyStore;

public final class GenericModuleFragment extends BaseFragment {
    private static final String ARG_MODULE_ID = "module_id";
    private static final String ARG_FOCUS_RECORD_ID = "focus_record_id";
    private FragmentModuleListBinding binding;
    private ModuleSpec spec;
    private RecordAdapter adapter;
    private int page = 1;
    private int totalPages;
    private boolean loaded;
    private boolean selectionMode;
    private long focusRecordId;
    private boolean focusHandled;
    private String pendingImageUploadPath = "";
    private String pendingImageUploadTitle = "";
    private final Map<String, JsonObject> selectedRecords = new LinkedHashMap<>();
    private final ActivityResultLauncher<Intent> bountyPublisher = registerForActivityResult(
        new ActivityResultContracts.StartActivityForResult(), result -> {
            if (result.getResultCode() == Activity.RESULT_OK && binding != null) {
                page = 1;
                load(false);
            }
        });
    private final ActivityResultLauncher<Intent> imageUploader = registerForActivityResult(
        new ActivityResultContracts.StartActivityForResult(), this::uploadSelectedImage);

    public static GenericModuleFragment newInstance(String moduleId) {
        return newInstance(moduleId, 0L);
    }

    public static GenericModuleFragment newInstance(String moduleId, long focusRecordId) {
        GenericModuleFragment fragment = new GenericModuleFragment();
        Bundle arguments = new Bundle();
        arguments.putString(ARG_MODULE_ID, moduleId);
        if (focusRecordId > 0) arguments.putLong(ARG_FOCUS_RECORD_ID, focusRecordId);
        fragment.setArguments(arguments);
        return fragment;
    }

    @Override
    public void onCreate(@Nullable Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        String id = requireArguments().getString(ARG_MODULE_ID, "");
        focusRecordId = requireArguments().getLong(ARG_FOCUS_RECORD_ID, 0L);
        spec = app().modules().find(app().session().role(), id);
        if (spec == null) throw new IllegalArgumentException("Unknown module: " + id);
    }

    @Nullable
    @Override
    public View onCreateView(@NonNull LayoutInflater inflater, @Nullable ViewGroup container, @Nullable Bundle savedInstanceState) {
        binding = FragmentModuleListBinding.inflate(inflater, container, false);
        host().setPageTitle(spec.title());
        adapter = new RecordAdapter(spec, new RecordAdapter.Listener() {
            @Override public void onRecordClick(JsonObject item) { recordClicked(item); }
            @Override public void onRecordLongPress(JsonObject item) {
                if (supportsBatchEntitlement()) toggleSelection(item);
                else {
                    Context context = activeContext();
                    if (context == null) return;
                    JsonObject snapshot = item == null ? new JsonObject() : item.deepCopy();
                    RecordDetailDialog.show(context, spec.title() + "信息", snapshot,
                        spec.itemActions().isEmpty() ? null : () -> {
                            if (activeContext() != null) showActionDialog(snapshot);
                        });
                }
            }
            @Override public void onRecordActions(View anchor, JsonObject item) { showActions(anchor, item); }
            @Override public void onRecordSelectionToggle(JsonObject item) { toggleSelection(item); }
        });
        adapter.setSelectedAppId(app().session().selectedAppId());
        binding.recycler.setLayoutManager(new LinearLayoutManager(requireContext()));
        binding.recycler.setAdapter(adapter);
        binding.swipeRefresh.setColorSchemeColors(
            xyz.jjmxg.yiyunying.ui.common.ThemeColors.primary(requireContext()),
            xyz.jjmxg.yiyunying.ui.common.ThemeColors.secondary(requireContext()),
            requireContext().getColor(xyz.jjmxg.yiyunying.R.color.tertiary));
        binding.swipeRefresh.setOnRefreshListener(() -> {
            page = 1;
            load(false);
        });
        binding.retryButton.setOnClickListener(view -> load(true));
        binding.previousButton.setOnClickListener(view -> {
            if (page > 1) { page--; load(true); }
        });
        binding.nextButton.setOnClickListener(view -> {
            if (page < totalPages) { page++; load(true); }
        });
        configureSearch();
        configureBatchSelection();
        if (spec.createAction() != null) {
            binding.fab.setVisibility(View.VISIBLE);
            binding.fab.setText(spec.createAction().title());
            binding.fab.setContentDescription(spec.createAction().title());
            binding.fab.setOnClickListener(view -> {
                if ("bounties".equals(spec.id()) && app().session().role() == Role.USER) {
                    bountyPublisher.launch(new Intent(requireContext(), BountyPublishActivity.class));
                } else {
                    executeAction(spec.createAction(), null);
                }
            });
        }
        load(true);
        return binding.getRoot();
    }

    private void configureSearch() {
        if (!spec.searchable()) return;
        binding.searchLayout.setVisibility(View.VISIBLE);
        binding.searchLayout.setEndIconOnClickListener(view -> search());
        binding.searchInput.setOnEditorActionListener((view, actionId, event) -> {
            if (actionId == EditorInfo.IME_ACTION_SEARCH) {
                search();
                return true;
            }
            return false;
        });
    }

    private void search() {
        page = 1;
        load(true);
    }

    private void load(boolean fullLoading) {
        final String path;
        try {
            path = PathResolver.resolve(spec.listPath(), app().session(), null);
        } catch (IllegalArgumentException exception) {
            host().requestAppSelection();
            return;
        }
        if (fullLoading && !loaded) {
            binding.progress.setVisibility(View.VISIBLE);
            binding.errorState.setVisibility(View.GONE);
            binding.emptyState.setVisibility(View.GONE);
        }
        Map<String, String> query = new LinkedHashMap<>();
        if (spec.paged()) {
            query.put("page", String.valueOf(page));
            query.put("limit", "20");
        }
        if (spec.searchable()) {
            String keyword = binding.searchInput.getText() == null ? "" : binding.searchInput.getText().toString().trim();
            if (!keyword.isEmpty()) query.put(spec.searchParameter(), keyword);
        }
        app().repository().getCached(path, query, cached -> {
            if (binding == null || !cached.isSuccessful()) return;
            UiGuard.run(binding.getRoot(), "module-cache/" + spec.id(), () -> {
                loaded = true;
                binding.progress.setVisibility(View.GONE);
                binding.errorState.setVisibility(View.GONE);
                List<JsonObject> cachedItems = extractItems(cached.dataObject());
                adapter.submit(cachedItems);
                adapter.setSelectedAppId(app().session().selectedAppId());
                adapter.setSelection(selectionMode, selectedRecords.keySet());
                binding.emptyState.setVisibility(cachedItems.isEmpty() ? View.VISIBLE : View.GONE);
                updatePagination(cached.dataObject());
                openFocusedRecord(cachedItems);
            });
        });
        track(app().repository().get(path, query, result -> {
            if (binding == null) return;
            binding.progress.setVisibility(View.GONE);
            binding.swipeRefresh.setRefreshing(false);
            if (!result.isSuccessful()) {
                if (result.isAuthenticationFailure()) {
                    host().onAuthenticationExpired();
                    return;
                }
                if (!loaded) {
                    binding.errorMessage.setText(result.message().isEmpty() ? "数据加载失败" : result.message());
                    binding.errorState.setVisibility(View.VISIBLE);
                } else {
                    handleFailure(result, binding.getRoot());
                }
                return;
            }
            UiGuard.run(binding.getRoot(), "模块列表渲染/" + spec.id(), () -> {
                loaded = true;
                binding.errorState.setVisibility(View.GONE);
                List<JsonObject> items = extractItems(result.dataObject());
                adapter.submit(items);
                adapter.setSelectedAppId(app().session().selectedAppId());
                adapter.setSelection(selectionMode, selectedRecords.keySet());
                binding.emptyState.setVisibility(items.isEmpty() ? View.VISIBLE : View.GONE);
                updatePagination(result.dataObject());
                openFocusedRecord(items);
            });
        }));
    }

    private void configureBatchSelection() {
        if (!supportsBatchEntitlement()) return;
        binding.batchModeButton.setVisibility(View.VISIBLE);
        binding.batchModeButton.setOnClickListener(view -> setSelectionMode(true));
        binding.clearSelectionButton.setOnClickListener(view -> {
            selectedRecords.clear();
            setSelectionMode(false);
        });
        binding.selectPageButton.setOnClickListener(view -> {
            setSelectionMode(true);
            for (JsonObject item : adapter.currentItems()) selectedRecords.put(recordId(item), item);
            updateSelectionUi();
        });
        binding.batchAdjustButton.setOnClickListener(view -> showBatchEntitlement());
    }

    private void toggleSelection(JsonObject item) {
        if (!supportsBatchEntitlement()) return;
        setSelectionMode(true);
        String id = recordId(item);
        if (selectedRecords.containsKey(id)) selectedRecords.remove(id); else selectedRecords.put(id, item);
        updateSelectionUi();
    }

    private void setSelectionMode(boolean enabled) {
        selectionMode = enabled;
        binding.batchBar.setVisibility(enabled ? View.VISIBLE : View.GONE);
        binding.batchModeButton.setVisibility(enabled ? View.GONE : View.VISIBLE);
        updateSelectionUi();
    }

    private void updateSelectionUi() {
        if (binding == null || adapter == null) return;
        binding.selectionCount.setText("已选择 " + selectedRecords.size() + " 项 · 可翻页继续选择");
        binding.batchAdjustButton.setEnabled(!selectedRecords.isEmpty());
        adapter.setSelection(selectionMode, new LinkedHashSet<>(selectedRecords.keySet()));
    }

    private void showBatchEntitlement() {
        if (selectedRecords.isEmpty()) {
            message(binding.getRoot(), "请先选择账号");
            return;
        }
        EntitlementEditorDialog.show(requireContext(), entitlementKind(), selectedRecords.size(), body -> {
            JsonArray ids = new JsonArray();
            for (String value : selectedRecords.keySet()) {
                try { ids.add(Long.parseLong(value)); }
                catch (NumberFormatException ignored) { }
            }
            body.add("target_ids", ids);
            binding.progress.setVisibility(View.VISIBLE);
            track(app().repository().put(batchEntitlementPath(), body, result -> {
                if (binding == null) return;
                binding.progress.setVisibility(View.GONE);
                if (handleFailure(result, binding.getRoot())) return;
                message(binding.getRoot(), result.message().isEmpty() ? "批量调整完成" : result.message());
                selectedRecords.clear();
                setSelectionMode(false);
                load(false);
            }));
        });
    }

    private boolean supportsBatchEntitlement() {
        return "operators".equals(spec.id()) || "admins".equals(spec.id()) || "users".equals(spec.id());
    }

    private String batchEntitlementPath() {
        if ("operators".equals(spec.id())) return "/api/platform/operators/batch-entitlement";
        if ("admins".equals(spec.id())) return "/api/platform/admins/batch-entitlement";
        return "/api/admin/apps/" + app().session().selectedAppId() + "/users/batch-entitlement";
    }

    private String recordId(JsonObject item) {
        String value = Jsons.string(item, spec.idKey());
        return value.isEmpty() ? String.valueOf(item.hashCode()) : value;
    }

    private List<JsonObject> extractItems(JsonObject data) {
        JsonArray array = new JsonArray();
        if (data.has(spec.dataKey()) && data.get(spec.dataKey()).isJsonArray()) {
            array = data.getAsJsonArray(spec.dataKey());
        } else if (data.has("items") && data.get("items").isJsonArray()) {
            array = data.getAsJsonArray("items");
        } else if (!data.entrySet().isEmpty()) {
            array.add(data);
        }
        List<JsonObject> items = new ArrayList<>();
        for (JsonElement element : array) if (element.isJsonObject()) items.add(element.getAsJsonObject());
        return items;
    }

    private void openFocusedRecord(List<JsonObject> items) {
        if (focusHandled || focusRecordId <= 0 || binding == null) return;
        for (JsonObject item : items) {
            if (String.valueOf(focusRecordId).equals(recordId(item))) {
                focusHandled = true;
                binding.recycler.post(() -> {
                    if (binding != null && activeContext() != null) recordClicked(item);
                });
                return;
            }
        }
        if (!"bounties".equals(spec.id()) || app().session().role() != Role.USER) {
            focusHandled = true;
            message(binding.getRoot(), "该收藏来源已不在当前列表中");
            return;
        }
        focusHandled = true;
        track(app().repository().get("/api/user/bounties/" + focusRecordId, new LinkedHashMap<>(), result -> {
            Context context = activeContext();
            if (context == null || binding == null) return;
            if (handleFailure(result, binding.getRoot())) return;
            JsonObject data = result.dataObject();
            JsonObject bounty = data.has("bounty") && data.get("bounty").isJsonObject()
                ? data.getAsJsonObject("bounty") : data;
            recordClicked(bounty);
        }));
    }

    private void updatePagination(JsonObject data) {
        if (!spec.paged()) {
            binding.pagination.setVisibility(View.GONE);
            return;
        }
        JsonObject pagination = Jsons.object(data, "pagination");
        page = Jsons.intValue(pagination, "page", page);
        totalPages = Jsons.intValue(pagination, "total_pages", 0);
        int total = Jsons.intValue(pagination, "total", 0);
        binding.pagination.setVisibility(View.VISIBLE);
        binding.pageText.setText((totalPages == 0 ? 0 : page) + " / " + totalPages + " · " + total + " 条");
        binding.previousButton.setEnabled(page > 1);
        binding.nextButton.setEnabled(page < totalPages);
    }

    private void recordClicked(JsonObject item) {
        Context context = activeContext();
        if (context == null || item == null) return;
        JsonObject snapshot = item.deepCopy();
        if (app().session().role() == Role.PLATFORM && "apps".equals(spec.id())) {
            ManagedUsersActivity.open(context, Jsons.longValue(snapshot, "id"), Jsons.string(snapshot, "name"));
            return;
        }
        if (supportsEntityProfile()) {
            loadEntityProfile(snapshot);
            return;
        }
        if (app().session().role() == Role.ADMIN && "apps".equals(spec.id())) {
            app().session().selectApp(Jsons.longValue(snapshot, "id"), Jsons.string(snapshot, "name"),
                Jsons.string(snapshot, "app_key"));
            adapter.setSelectedAppId(app().session().selectedAppId());
            host().onAppSelectionChanged();
            message(binding.getRoot(), "已切换到 " + Jsons.string(snapshot, "name"));
            return;
        }
        if ("chat_rooms".equals(spec.id())) {
            if (app().session().role() == Role.ADMIN) {
                String entity = "chat_room".equals(Jsons.string(snapshot, "room_kind")) ? "聊天室" : "群聊";
                RecordDetailDialog.show(context, "用户" + entity + "资料", snapshot);
            } else if (booleanValue(snapshot, "joined")) {
                ChatActivity.openRoom(context, Jsons.longValue(snapshot, "id"), Jsons.string(snapshot, "name"));
            } else {
                joinGroup(snapshot);
            }
            return;
        }
        if (app().session().role() == Role.USER && "conversations".equals(spec.id())) {
            String title = Jsons.string(snapshot, "peer_name");
            if (title.isEmpty()) title = Jsons.string(snapshot, "peer_account");
            ChatActivity.openConversation(context, Jsons.longValue(snapshot, "id"),
                Jsons.longValue(snapshot, "peer_user_id"), title);
            return;
        }
        if (app().session().role() == Role.ADMIN && "service_sessions".equals(spec.id())) {
            String title = Jsons.string(snapshot, "nickname");
            if (title.isEmpty()) title = Jsons.string(snapshot, "account");
            ChatActivity.openAdminService(context, Jsons.longValue(snapshot, "id"), "客服 · " + title);
            return;
        }
        long authorUserId = recordUserId(snapshot);
        if (app().session().role() == Role.USER && authorUserId > 0) {
            RecordDetailDialog.show(context, spec.title(), snapshot, "查看发布者", () -> {
                Context current = activeContext();
                if (current != null) UserProfileActivity.open(current, authorUserId);
            });
        } else {
            RecordDetailDialog.show(context, spec.title(), snapshot);
        }
    }

    private long recordUserId(JsonObject item) {
        for (String key : new String[]{"user_id", "author_user_id", "creator_user_id", "owner_user_id"}) {
            long value = Jsons.longValue(item, key);
            if (value > 0) return value;
        }
        JsonObject author = Jsons.object(item, "author");
        return Jsons.longValue(author, "user_id");
    }

    private boolean supportsEntityProfile() {
        return "operators".equals(spec.id()) || "admins".equals(spec.id()) || "users".equals(spec.id());
    }

    private void loadEntityProfile(JsonObject item) {
        if (binding == null || activeContext() == null) return;
        String id = recordId(item);
        String path;
        if ("operators".equals(spec.id())) path = "/api/platform/operators/" + id;
        else if ("admins".equals(spec.id())) path = "/api/platform/admins/" + id;
        else path = (app().session().role() == Role.ADMIN ? "/api/admin/apps/" : "/api/platform/apps/")
            + app().session().selectedAppId() + "/users/" + id;
        binding.progress.setVisibility(View.VISIBLE);
        track(app().repository().get(path, new LinkedHashMap<>(), result -> {
            Context context = activeContext();
            if (context == null || binding == null) return;
            binding.progress.setVisibility(View.GONE);
            if (handleFailure(result, binding.getRoot())) return;
            RecordDetailDialog.show(context, spec.title() + "资料", result.dataObject(),
                "权限与限制", () -> openRolePermissions(item));
        }));
    }

    private void openRolePermissions(JsonObject item) {
        Context context = activeContext();
        if (context == null) return;
        long targetId;
        try {
            targetId = Long.parseLong(recordId(item));
        } catch (NumberFormatException error) {
            message(binding.getRoot(), "权限对象编号无效");
            return;
        }
        String name = firstNonEmpty(Jsons.string(item, "nickname"), Jsons.string(item, "name"),
            Jsons.string(item, "account"), Jsons.string(item, "username"));
        String account = firstNonEmpty(Jsons.string(item, "account"), Jsons.string(item, "username"));
        if ("operators".equals(spec.id())) {
            RolePermissionActivity.openPlatform(context, targetId, name, account);
        } else if ("admins".equals(spec.id())) {
            RolePermissionActivity.openAdmin(context, targetId, name, account);
        } else {
            long selectedAppId = app().session().selectedAppId();
            if (selectedAppId <= 0) {
                message(binding.getRoot(), "请先选择用户所属应用");
                return;
            }
            RolePermissionActivity.openUser(context, selectedAppId, targetId, name, account);
        }
    }

    private void showActionDialog(JsonObject item) {
        Context context = activeContext();
        if (context == null) return;
        List<GlassActionDialog.Action> actions = new ArrayList<>();
        for (ActionSpec action : spec.itemActions()) {
            actions.add(new GlassActionDialog.Action(shortActionTitle(action.title()), actionIcon(action.title()),
                () -> { if (activeContext() != null) executeAction(action, item); }));
        }
        if (isReportableModule()) {
            actions.add(new GlassActionDialog.Action("举报", R.drawable.ic_more, () -> reportRecord(item)));
        }
        if (!actions.isEmpty()) GlassActionDialog.show(context, "内容操作", actions);
    }

    private void joinGroup(JsonObject item) {
        long roomId = Jsons.longValue(item, "id");
        if (roomId <= 0) {
            message(binding.getRoot(), "群聊 ID 无效");
            return;
        }
        binding.progress.setVisibility(View.VISIBLE);
        track(app().repository().post("/api/user/chat-rooms/" + roomId + "/join", new JsonObject(), result -> {
            if (binding == null) return;
            binding.progress.setVisibility(View.GONE);
            if (handleFailure(result, binding.getRoot())) return;
            if (booleanValue(result.dataObject(), "joined")) {
                Context context = activeContext();
                if (context != null) ChatActivity.openRoom(context, roomId, Jsons.string(item, "name"));
            } else {
                message(binding.getRoot(), result.message().isEmpty() ? "入群申请已提交" : result.message());
                load(false);
            }
        }));
    }

    private static boolean booleanValue(JsonObject object, String key) {
        if (object == null || !object.has(key) || object.get(key).isJsonNull()) return false;
        try { return object.get(key).getAsBoolean(); } catch (RuntimeException ignored) { return false; }
    }

    private void showActions(View anchor, JsonObject item) {
        Context context = activeContext();
        if (context == null) return;
        showActionDialog(item);
    }

    private boolean isReportableModule() {
        if (app().session().role() != Role.USER) return false;
        return "bounties".equals(spec.id()) || "resources".equals(spec.id())
            || "polls".equals(spec.id()) || "activities".equals(spec.id());
    }

    private void reportRecord(JsonObject item) {
        Context context = activeContext();
        if (context == null) return;
        String type = "bounties".equals(spec.id()) ? "bounty"
            : ("resources".equals(spec.id()) ? "resource"
            : ("polls".equals(spec.id()) ? "poll" : "activity"));
        String name = firstNonEmpty(Jsons.string(item, "title"), Jsons.string(item, "name"), spec.title());
        long targetId;
        try {
            targetId = Long.parseLong(recordId(item));
        } catch (NumberFormatException error) {
            message(binding.getRoot(), "举报对象编号无效");
            return;
        }
        ContentReportDialog.show(context, type, targetId, name);
    }

    private static String shortActionTitle(String title) {
        if (title == null) return "操作";
        return title.replace("或取消", "切换").replace("悬赏", "").trim();
    }

    private static int actionIcon(String title) {
        String value = title == null ? "" : title;
        if (value.contains("头像")) return R.drawable.ic_person;
        if (value.contains("评论") || value.contains("投稿")) return R.drawable.ic_chat;
        if (value.contains("购买") || value.contains("余额")) return R.drawable.ic_wallet;
        if (value.contains("收藏")) return R.drawable.ic_favorite;
        if (value.contains("删除") || value.contains("取消")) return R.drawable.ic_close;
        if (value.contains("编辑") || value.contains("审核")) return R.drawable.ic_document;
        return R.drawable.ic_more;
    }

    private void executeAction(ActionSpec action, JsonObject item) {
        Context context = activeContext();
        if (context == null) return;
        if ("UPLOAD_IMAGE".equalsIgnoreCase(action.method())) {
            startImageUpload(action, item);
            return;
        }
        if (action.pathTemplate().endsWith("/entitlement")) {
            EntitlementEditorDialog.show(context, entitlementKind(), 1,
                body -> submitAction(action, item, body));
            return;
        }
        DynamicFormDialog.show(context, action, item, body -> {
            Context callbackContext = activeContext();
            if (callbackContext == null) return;
            for (Map.Entry<String, String> entry : action.fixedValues().entrySet()) {
                body.addProperty(entry.getKey(), entry.getValue());
            }
            if (action.confirmationRequired() && !action.fields().isEmpty()) {
                new YiyunyingDialogBuilder(callbackContext)
                    .setTitle(action.title())
                    .setMessage(action.destructive() ? "此操作会改变或删除数据，是否继续？" : "确认提交当前操作？")
                    .setNegativeButton("取消", null)
                    .setPositiveButton("确定", (dialog, which) -> submitAction(action, item, body))
                    .show();
            } else {
                submitAction(action, item, body);
            }
        });
    }

    private void startImageUpload(ActionSpec action, JsonObject item) {
        if (!pendingImageUploadPath.isEmpty()) {
            if (binding != null) message(binding.getRoot(), "请等待当前图片上传完成");
            return;
        }
        try {
            pendingImageUploadPath = PathResolver.resolve(action.pathTemplate(), app().session(), item);
        } catch (IllegalArgumentException exception) {
            if (binding != null) message(binding.getRoot(), exception.getMessage());
            return;
        }
        pendingImageUploadTitle = action.title();
        Context context = activeContext();
        if (context == null) {
            pendingImageUploadPath = "";
            pendingImageUploadTitle = "";
            return;
        }
        imageUploader.launch(MediaPickerActivity.imageIntent(context, 1));
    }

    private void uploadSelectedImage(androidx.activity.result.ActivityResult pickerResult) {
        String path = pendingImageUploadPath;
        if (path.isEmpty()) return;
        if (pickerResult.getResultCode() != Activity.RESULT_OK || pickerResult.getData() == null) {
            pendingImageUploadPath = "";
            pendingImageUploadTitle = "";
            return;
        }
        ArrayList<Uri> uris = pickerResult.getData()
            .getParcelableArrayListExtra(MediaPickerActivity.EXTRA_SELECTED_URIS);
        Uri uri = uris == null || uris.isEmpty() ? null : uris.get(0);
        Context context = activeContext();
        if (uri == null || context == null || binding == null) {
            pendingImageUploadPath = "";
            pendingImageUploadTitle = "";
            return;
        }
        String name = "avatar.jpg";
        long size = -1L;
        try (Cursor cursor = context.getContentResolver().query(
            uri, new String[]{OpenableColumns.DISPLAY_NAME, OpenableColumns.SIZE}, null, null, null)) {
            if (cursor != null && cursor.moveToFirst()) {
                int nameColumn = cursor.getColumnIndex(OpenableColumns.DISPLAY_NAME);
                int sizeColumn = cursor.getColumnIndex(OpenableColumns.SIZE);
                if (nameColumn >= 0 && !cursor.isNull(nameColumn)) name = cursor.getString(nameColumn);
                if (sizeColumn >= 0 && !cursor.isNull(sizeColumn)) size = cursor.getLong(sizeColumn);
            }
        } catch (RuntimeException ignored) { }
        String mime = context.getContentResolver().getType(uri);
        if (mime == null || !mime.startsWith("image/")) mime = "image/jpeg";
        if (!UploadPolicyStore.accepts(context, "image", size)) {
            message(binding.getRoot(), UploadPolicyStore.rejectionMessage(context, "image", size));
            pendingImageUploadPath = "";
            pendingImageUploadTitle = "";
            return;
        }
        String successTitle = pendingImageUploadTitle;
        pendingImageUploadTitle = "";
        binding.progress.setVisibility(View.VISIBLE);
        track(app().repository().upload(
            path,
            name,
            mime,
            new ContentUriRequestBody(context.getContentResolver(), uri, mime, size),
            new LinkedHashMap<>(),
            result -> {
                pendingImageUploadPath = "";
                if (binding == null) return;
                binding.progress.setVisibility(View.GONE);
                if (handleFailure(result, binding.getRoot())) return;
                message(binding.getRoot(), result.message().isEmpty() ? successTitle + "成功" : result.message());
                load(false);
            }
        ));
    }

    private EntitlementEditorDialog.TargetKind entitlementKind() {
        if ("operators".equals(spec.id())) return EntitlementEditorDialog.TargetKind.AUTHORIZED_PLATFORM;
        if ("admins".equals(spec.id())) return EntitlementEditorDialog.TargetKind.ADMIN;
        return EntitlementEditorDialog.TargetKind.USER;
    }

    private void submitAction(ActionSpec action, JsonObject item, JsonObject body) {
        final String path;
        try {
            path = PathResolver.resolve(action.pathTemplate(), app().session(), item);
        } catch (IllegalArgumentException exception) {
            message(binding.getRoot(), exception.getMessage());
            return;
        }
        binding.progress.setVisibility(View.VISIBLE);
        String idempotencyKey = action.idempotent() ? UUID.randomUUID().toString() : "";
        Map<String, String> query = new LinkedHashMap<>();
        JsonObject requestBody = body;
        if ("GET".equalsIgnoreCase(action.method())) {
            for (Map.Entry<String, JsonElement> entry : body.entrySet()) {
                if (!entry.getValue().isJsonNull()) query.put(entry.getKey(), entry.getValue().getAsString());
            }
            requestBody = new JsonObject();
        }
        track(app().repository().request(action.method(), path, requestBody, query, AuthMode.SESSION,
            idempotencyKey, result -> {
                if (binding == null) return;
                binding.progress.setVisibility(View.GONE);
                if (handleFailure(result, binding.getRoot())) return;
                if ("GET".equalsIgnoreCase(action.method())) {
                    Context context = activeContext();
                    if (context != null) RecordDetailDialog.show(context, action.title(), result.dataObject());
                    return;
                }
                message(binding.getRoot(), result.message().isEmpty() ? "操作成功" : result.message());
                if (action.refreshAfter()) load(false);
            }));
    }

    private static String firstNonEmpty(String... values) {
        for (String value : values) if (value != null && !value.isEmpty()) return value;
        return "";
    }

    @Nullable
    private Context activeContext() {
        return binding != null && isAdded() ? getContext() : null;
    }

    @Override
    public void onDestroyView() {
        pendingImageUploadPath = "";
        pendingImageUploadTitle = "";
        binding = null;
        super.onDestroyView();
    }
}
