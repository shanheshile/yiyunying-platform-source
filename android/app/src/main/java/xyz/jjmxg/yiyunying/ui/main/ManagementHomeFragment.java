package xyz.jjmxg.yiyunying.ui.main;

import android.os.Bundle;
import android.text.TextUtils;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.widget.HorizontalScrollView;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.TextView;

import androidx.annotation.NonNull;
import androidx.annotation.Nullable;

import com.google.android.material.button.MaterialButton;
import com.google.gson.JsonObject;
import com.google.gson.JsonElement;

import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.databinding.FragmentManagementPageBinding;
import xyz.jjmxg.yiyunying.domain.module.ActionSpec;
import xyz.jjmxg.yiyunying.domain.module.FieldSpec;
import xyz.jjmxg.yiyunying.ui.common.BaseFragment;
import xyz.jjmxg.yiyunying.ui.common.DynamicFormDialog;
import xyz.jjmxg.yiyunying.ui.common.ImageLoader;
import xyz.jjmxg.yiyunying.ui.common.YiyunyingDialogBuilder;

public final class ManagementHomeFragment extends BaseFragment {
    private FragmentManagementPageBinding binding;
    private final List<JsonObject> apps = new ArrayList<>();
    private final Map<String, Boolean> permissions = new LinkedHashMap<>();
    private JsonObject selectedApp;
    private boolean permissionsLoaded;

    public static ManagementHomeFragment newInstance() { return new ManagementHomeFragment(); }

    @Nullable @Override
    public View onCreateView(@NonNull LayoutInflater inflater, @Nullable ViewGroup container, @Nullable Bundle state) {
        binding = FragmentManagementPageBinding.inflate(inflater, container, false);
        renderLoading();
        if (!ManagementNavigationPolicy.useAdminWorkbench(app().session().role())) {
            binding.pageContent.removeAllViews();
            binding.pageContent.addView(ManagementPageUi.title(requireContext(), "管理主页"));
            binding.pageContent.addView(ManagementPageUi.body(requireContext(), "当前账号继续使用原平台功能目录。"));
            return binding.getRoot();
        }
        loadApps();
        loadPermissions();
        return binding.getRoot();
    }

    @Override public void onResume() {
        super.onResume();
        if (binding != null && !apps.isEmpty()) render();
    }

    private void renderLoading() {
        binding.pageContent.removeAllViews();
        binding.pageContent.addView(ManagementPageUi.title(requireContext(), "管理主页"));
        binding.pageContent.addView(ManagementPageUi.body(requireContext(), "正在读取我的应用与实时状态…"));
    }

    private void loadApps() {
        Map<String, String> query = new LinkedHashMap<>();
        query.put("page", "1");
        query.put("limit", "100");
        track(app().repository().get("/api/admin/apps", query, result -> {
            if (binding == null || handleFailure(result, binding.getRoot())) return;
            apps.clear();
            apps.addAll(result.objectItems());
            selectedApp = findSelected();
            if (selectedApp == null && !apps.isEmpty()) {
                selectedApp = apps.get(0);
                app().session().selectApp(Jsons.longValue(selectedApp, "id"),
                    Jsons.string(selectedApp, "name"), Jsons.string(selectedApp, "app_key"));
            }
            render();
        }));
    }

    private void loadPermissions() {
        track(app().repository().get("/api/admin/permissions", new LinkedHashMap<>(), result -> {
            if (binding == null) return;
            permissionsLoaded = true;
            permissions.clear();
            if (result.isSuccessful()) {
                JsonObject values = Jsons.object(result.dataObject(), "permissions");
                for (Map.Entry<String, JsonElement> entry : values.entrySet()) {
                    boolean allowed = false;
                    try {
                        allowed = entry.getValue().isJsonObject()
                            ? entry.getValue().getAsJsonObject().get("allowed").getAsBoolean()
                            : entry.getValue().getAsBoolean();
                    } catch (RuntimeException ignored) { }
                    permissions.put(entry.getKey(), allowed);
                }
            }
            render();
        }));
    }

    private JsonObject findSelected() {
        long selectedId = app().session().selectedAppId();
        for (JsonObject item : apps) if (Jsons.longValue(item, "id") == selectedId) return item;
        return null;
    }

    private void render() {
        if (binding == null) return;
        LinearLayout content = binding.pageContent;
        content.removeAllViews();
        content.addView(ManagementPageUi.title(requireContext(), "主页"));
        content.addView(ManagementPageUi.body(requireContext(), "选择一个应用后，可直接控制该应用下的全部系统。"));

        content.addView(ManagementPageUi.heading(requireContext(), "当前应用"));
        content.addView(currentAppCard());
        content.addView(ManagementPageUi.heading(requireContext(), "轮播图"));
        renderBannerPlaceholder(content);
        if (selectedApp != null) loadBanners(Jsons.longValue(selectedApp, "id"));

        LinearLayout applicationsHeading = ManagementPageUi.row(requireContext());
        TextView heading = ManagementPageUi.heading(requireContext(), "我的应用 · " + apps.size());
        ManagementPageUi.addWeighted(applicationsHeading, heading, ManagementPageUi.dp(requireContext(), 8));
        MaterialButton add = ManagementPageUi.button(requireContext(), "添加应用", R.drawable.ic_add, true);
        add.setEnabled(permissionAllowed("apps"));
        add.setOnClickListener(view -> showCreateApp());
        applicationsHeading.addView(add, new LinearLayout.LayoutParams(-2, -2));
        content.addView(applicationsHeading);
        if (apps.isEmpty()) {
            content.addView(ManagementPageUi.card(requireContext(), paddedBody("还没有应用。点击“添加应用”，应用唯一 ID 与密钥会由服务器安全生成。")));
        } else {
            for (JsonObject item : apps) content.addView(appCard(item));
        }

        content.addView(ManagementPageUi.heading(requireContext(), "当前应用的系统"));
        if (selectedApp == null) {
            content.addView(ManagementPageUi.card(requireContext(), paddedBody("请先添加或选择应用。")));
            return;
        }
        for (ManagementNavigationPolicy.SystemEntry system : ManagementNavigationPolicy.systems()) {
            if (firstAllowedModule(system) != null) content.addView(systemCard(system));
        }
    }

    private View currentAppCard() {
        LinearLayout box = ManagementPageUi.column(requireContext(), ManagementPageUi.dp(requireContext(), 16));
        if (selectedApp == null) {
            box.addView(ManagementPageUi.title(requireContext(), "尚未选择应用"));
            box.addView(ManagementPageUi.body(requireContext(), "添加应用后，唯一 ID、应用 KEY 与系统控制入口会显示在这里。"));
            return ManagementPageUi.card(requireContext(), box);
        }
        String name = Jsons.string(selectedApp, "name");
        String type = ManagementNavigationPolicy.appTypeName(Jsons.string(selectedApp, "app_type"));
        String key = Jsons.string(selectedApp, "app_key");
        box.addView(ManagementPageUi.title(requireContext(), name));
        box.addView(ManagementPageUi.body(requireContext(), "类型：" + type + "\n应用唯一 API ID：" + key
            + "\n用户数：" + Jsons.longValue(selectedApp, "user_count") + " · 文档数：" + Jsons.longValue(selectedApp, "document_count")));
        LinearLayout actions = ManagementPageUi.row(requireContext());
        MaterialButton settings = ManagementPageUi.button(requireContext(), "应用规则", R.drawable.ic_settings, false);
        settings.setOnClickListener(view -> host().openModule("app_settings"));
        settings.setEnabled(permissionAllowed("app_settings"));
        ManagementPageUi.addWeighted(actions, settings, ManagementPageUi.dp(requireContext(), 8));
        MaterialButton statistics = ManagementPageUi.button(requireContext(), "数据统计", R.drawable.ic_stats, false);
        statistics.setOnClickListener(view -> host().openModule("statistics"));
        statistics.setEnabled(permissionAllowed("statistics"));
        actions.addView(statistics, new LinearLayout.LayoutParams(0, -2, 1f));
        box.addView(actions);
        return ManagementPageUi.card(requireContext(), box);
    }

    private View appCard(JsonObject item) {
        LinearLayout box = ManagementPageUi.column(requireContext(), ManagementPageUi.dp(requireContext(), 14));
        long id = Jsons.longValue(item, "id");
        boolean selected = selectedApp != null && Jsons.longValue(selectedApp, "id") == id;
        box.addView(ManagementPageUi.title(requireContext(), Jsons.string(item, "name") + (selected ? " · 当前" : "")));
        box.addView(ManagementPageUi.body(requireContext(), ManagementNavigationPolicy.appTypeName(Jsons.string(item, "app_type"))
            + "\n唯一 ID：" + Jsons.string(item, "app_key")));
        LinearLayout actions = ManagementPageUi.row(requireContext());
        MaterialButton choose = ManagementPageUi.button(requireContext(), selected ? "已选择" : "控制此应用", R.drawable.ic_apps, selected);
        choose.setEnabled(!selected);
        choose.setOnClickListener(view -> applySelection(item));
        ManagementPageUi.addWeighted(actions, choose, ManagementPageUi.dp(requireContext(), 8));
        MaterialButton remove = ManagementPageUi.button(requireContext(), "删除", R.drawable.ic_delete, false);
        remove.setEnabled(permissionAllowed("apps"));
        remove.setOnClickListener(view -> confirmDelete(item));
        actions.addView(remove, new LinearLayout.LayoutParams(-2, -2));
        box.addView(actions);
        return ManagementPageUi.card(requireContext(), box);
    }

    private View systemCard(ManagementNavigationPolicy.SystemEntry entry) {
        LinearLayout box = ManagementPageUi.column(requireContext(), ManagementPageUi.dp(requireContext(), 14));
        box.addView(ManagementPageUi.title(requireContext(), entry.title));
        box.addView(ManagementPageUi.body(requireContext(), entry.description));
        String target = firstAllowedModule(entry);
        MaterialButton open = ManagementPageUi.button(requireContext(), "进入" + entry.title, iconFor(target), false);
        open.setOnClickListener(view -> host().openModule(target));
        box.addView(open);
        if (!entry.embeddedModules.isEmpty()) {
            TextView embedded = ManagementPageUi.body(requireContext(), "内嵌管理：" + embeddedNames(entry.embeddedModules));
            embedded.setPadding(0, ManagementPageUi.dp(requireContext(), 6), 0, 0);
            box.addView(embedded);
        }
        return ManagementPageUi.card(requireContext(), box);
    }

    private String embeddedNames(List<String> modules) {
        List<String> names = new ArrayList<>();
        for (String id : modules) {
            if (!permissionAllowed(id)) continue;
            xyz.jjmxg.yiyunying.domain.module.ModuleSpec spec = app().modules().find(app().session().role(), id);
            if (spec != null && !TextUtils.isEmpty(spec.title())) names.add(spec.title());
        }
        return TextUtils.join("、", names);
    }

    private void renderBannerPlaceholder(LinearLayout content) {
        HorizontalScrollView scroll = new HorizontalScrollView(requireContext());
        scroll.setHorizontalScrollBarEnabled(false);
        LinearLayout row = ManagementPageUi.row(requireContext());
        row.setTag(R.id.management_banner_container);
        TextView placeholder = paddedBody(selectedApp == null ? "选择应用后查看轮播图。" : "正在加载轮播图…");
        row.addView(placeholder, new LinearLayout.LayoutParams(ManagementPageUi.dp(requireContext(), 260), -2));
        scroll.addView(row, new HorizontalScrollView.LayoutParams(-2, -2));
        content.addView(ManagementPageUi.card(requireContext(), scroll));
    }

    private void loadBanners(long appId) {
        track(app().repository().get("/api/admin/apps/" + appId + "/banners", new LinkedHashMap<>(), result -> {
            if (binding == null || !result.isSuccessful() || selectedApp == null || Jsons.longValue(selectedApp, "id") != appId) return;
            LinearLayout row = binding.getRoot().findViewWithTag(R.id.management_banner_container);
            if (row == null) return;
            row.removeAllViews();
            List<JsonObject> banners = result.objectItems();
            if (banners.isEmpty()) {
                TextView empty = paddedBody("暂无轮播图，可进入轮播图管理添加。 ");
                row.addView(empty, new LinearLayout.LayoutParams(ManagementPageUi.dp(requireContext(), 260), -2));
            } else {
                for (JsonObject banner : banners) row.addView(bannerView(banner));
            }
            MaterialButton manage = ManagementPageUi.button(requireContext(), "管理轮播图", R.drawable.ic_apps, false);
            manage.setOnClickListener(view -> host().openModule("banners"));
            LinearLayout.LayoutParams params = new LinearLayout.LayoutParams(ManagementPageUi.dp(requireContext(), 160), -2);
            params.setMargins(ManagementPageUi.dp(requireContext(), 8), ManagementPageUi.dp(requireContext(), 8), ManagementPageUi.dp(requireContext(), 8), ManagementPageUi.dp(requireContext(), 8));
            row.addView(manage, params);
        }));
    }

    private View bannerView(JsonObject banner) {
        LinearLayout box = ManagementPageUi.column(requireContext(), ManagementPageUi.dp(requireContext(), 8));
        ImageView image = new ImageView(requireContext());
        image.setScaleType(ImageView.ScaleType.CENTER_CROP);
        image.setContentDescription("轮播图：" + Jsons.string(banner, "title"));
        ImageLoader.get().load(ImageLoader.get().absoluteUrl(requireContext(), Jsons.string(banner, "image_url")), image, R.drawable.ic_apps);
        box.addView(image, new LinearLayout.LayoutParams(ManagementPageUi.dp(requireContext(), 220), ManagementPageUi.dp(requireContext(), 100)));
        TextView title = ManagementPageUi.body(requireContext(), Jsons.string(banner, "title"));
        title.setMaxLines(1);
        box.addView(title);
        LinearLayout.LayoutParams params = new LinearLayout.LayoutParams(ManagementPageUi.dp(requireContext(), 236), -2);
        params.setMarginEnd(ManagementPageUi.dp(requireContext(), 8));
        box.setLayoutParams(params);
        return box;
    }

    private void showCreateApp() {
        String[] labels = ManagementNavigationPolicy.appTypeLabels();
        new YiyunyingDialogBuilder(requireContext())
            .setTitle("选择应用类型")
            .setItems(labels, (dialog, which) -> showCreateAppForm(which))
            .setNegativeButton("取消", null)
            .show();
    }

    private void showCreateAppForm(int appTypeIndex) {
        String appTypeCode = ManagementNavigationPolicy.appTypeCode(appTypeIndex);
        String appTypeName = ManagementNavigationPolicy.appTypeName(appTypeCode);
        ActionSpec action = ActionSpec.builder("添加" + appTypeName, "POST", "/api/admin/apps")
            .fields(
                FieldSpec.required("name", "应用名称"),
                FieldSpec.of("description", "应用说明"),
                FieldSpec.of("logo", "应用图标地址（可选）")
            ).build();
        DynamicFormDialog.show(requireContext(), action, null, body -> {
            body.addProperty("app_type", appTypeCode);
            track(app().repository().post("/api/admin/apps", body, result -> {
                if (binding == null || handleFailure(result, binding.getRoot())) return;
                JsonObject data = result.dataObject();
                JsonObject created = Jsons.object(data, "app");
                String secret = Jsons.string(data, "app_secret");
                new YiyunyingDialogBuilder(requireContext())
                    .setTitle("应用创建成功")
                    .setMessage("应用唯一 ID：" + Jsons.string(created, "app_key")
                        + "\n\n仅本次显示的服务端密钥：\n" + secret
                        + "\n\n请保存在可信服务端，不要放进客户端或聊天记录。")
                    .setCancelable(false)
                    .setPositiveButton("我已安全保存", null)
                    .show();
                if (!created.entrySet().isEmpty()) applySelection(created);
                loadApps();
            }));
        });
    }

    private void confirmDelete(JsonObject item) {
        String name = Jsons.string(item, "name");
        new YiyunyingDialogBuilder(requireContext())
            .setTitle("删除应用")
            .setMessage("将停用“" + name + "”并保留审计历史。此操作不会删除其他应用，是否继续？")
            .setNegativeButton("取消", null)
            .setPositiveButton("删除", (dialog, which) -> {
                JsonObject body = new JsonObject();
                body.addProperty("confirm", name);
                track(app().repository().delete("/api/admin/apps/" + Jsons.longValue(item, "id"), body, result -> {
                    if (binding == null || handleFailure(result, binding.getRoot())) return;
                    message(binding.getRoot(), "应用已删除");
                    if (selectedApp != null && Jsons.longValue(selectedApp, "id") == Jsons.longValue(item, "id")) {
                        app().session().selectApp(0, "", "");
                        selectedApp = null;
                    }
                    loadApps();
                }));
            }).show();
    }

    private void applySelection(JsonObject item) {
        selectedApp = item;
        app().session().selectApp(Jsons.longValue(item, "id"), Jsons.string(item, "name"), Jsons.string(item, "app_key"));
        // MainActivity refreshes the toolbar and app-scoped child pages while deliberately not
        // reopening the dashboard shell itself.
        host().onAppSelectionChanged();
        if (binding != null) render();
    }

    private TextView paddedBody(String text) {
        TextView view = ManagementPageUi.body(requireContext(), text);
        int padding = ManagementPageUi.dp(requireContext(), 16);
        view.setPadding(padding, padding, padding, padding);
        return view;
    }

    private int iconFor(String module) {
        if (module == null) return R.drawable.ic_apps;
        if (module.contains("user")) return R.drawable.ic_users;
        if (module.contains("chat") || module.contains("message")) return R.drawable.ic_chat;
        if (module.contains("forum") || module.contains("report")) return R.drawable.ic_forum;
        if (module.contains("document") || module.contains("notice")) return R.drawable.ic_document;
        if (module.contains("card") || module.contains("payment") || module.contains("shop")) return R.drawable.ic_wallet;
        if (module.contains("setting") || module.contains("domain")) return R.drawable.ic_settings;
        return R.drawable.ic_apps;
    }

    @Nullable private String firstAllowedModule(ManagementNavigationPolicy.SystemEntry entry) {
        if (ManagementNavigationPolicy.safeChildModule(entry.primaryModule)
            && permissionAllowed(entry.primaryModule)
            && app().modules().find(app().session().role(), entry.primaryModule) != null) {
            return entry.primaryModule;
        }
        for (String module : entry.embeddedModules) {
            if (ManagementNavigationPolicy.safeChildModule(module) && permissionAllowed(module)
                && app().modules().find(app().session().role(), module) != null) return module;
        }
        return null;
    }

    private boolean permissionAllowed(String module) {
        if (!permissionsLoaded) return false;
        String code = ManagementNavigationPolicy.permissionForModule(module);
        return code.isEmpty() || !permissions.containsKey(code) || Boolean.TRUE.equals(permissions.get(code));
    }

    @Override public void onDestroyView() {
        binding = null;
        selectedApp = null;
        apps.clear();
        permissions.clear();
        permissionsLoaded = false;
        super.onDestroyView();
    }
}
