package xyz.jjmxg.yiyunying.ui.main;

import android.os.Bundle;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.widget.HorizontalScrollView;
import android.widget.LinearLayout;
import android.widget.TextView;

import androidx.annotation.NonNull;
import androidx.annotation.Nullable;

import com.google.android.material.button.MaterialButton;
import com.google.android.material.chip.Chip;
import com.google.gson.JsonObject;

import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.databinding.FragmentManagementPageBinding;
import xyz.jjmxg.yiyunying.ui.common.BaseFragment;

public final class SourceExamplesFragment extends BaseFragment {
    private static final String[][] MODULE_FILTERS = {
        {"全部模块", ""}, {"好友聊天模块", "好友聊天"}, {"群聊模块", "群聊"},
        {"登录注册模块", "登录注册"}, {"论坛模块", "论坛"}, {"文档模块", "文档"},
        {"商城模块", "商城"}, {"完整示例", "完整示例"},
    };

    private FragmentManagementPageBinding binding;
    private final List<JsonObject> categories = new ArrayList<>();
    private long categoryId;
    private long loadedAppId = -1;
    private String keyword = "";
    private boolean permissionResolved;
    private boolean permissionAllowed;

    public static SourceExamplesFragment newInstance() { return new SourceExamplesFragment(); }

    @Nullable @Override
    public View onCreateView(@NonNull LayoutInflater inflater, @Nullable ViewGroup container, @Nullable Bundle state) {
        binding = FragmentManagementPageBinding.inflate(inflater, container, false);
        renderSkeleton("正在读取源码分类…");
        if (!ManagementNavigationPolicy.useAdminWorkbench(app().session().role())) {
            renderSkeleton("当前账号继续使用原平台源码目录。");
            return binding.getRoot();
        }
        loadPermission();
        return binding.getRoot();
    }

    @Override public void onResume() {
        super.onResume();
        if (binding == null || !ManagementNavigationPolicy.useAdminWorkbench(app().session().role())
            || !permissionResolved || !permissionAllowed) return;
        long selectedAppId = app().session().selectedAppId();
        if (selectedAppId == loadedAppId) return;
        categoryId = 0;
        keyword = "";
        categories.clear();
        if (selectedAppId == 0) {
            loadedAppId = 0;
            renderSkeleton("请先在“主页”添加或选择一个应用。源码示例按应用独立管理。");
        } else {
            renderSkeleton("正在读取源码分类…");
            loadCategories();
        }
    }

    private void loadPermission() {
        track(app().repository().get("/api/admin/permissions", new LinkedHashMap<>(), result -> {
            if (binding == null) return;
            permissionResolved = true;
            if (!result.isSuccessful()) {
                permissionAllowed = false;
                handleFailure(result, binding.getRoot());
                renderSkeleton("无法验证源码管理权限，请稍后重试。");
                return;
            }
            permissionAllowed = ManagementNavigationPolicy.permissionAllowed(result.dataObject(), "resources.manage");
            if (!permissionAllowed) {
                renderSkeleton("当前账号没有源码与资源管理权限。");
                return;
            }
            if (app().session().selectedAppId() == 0) {
                loadedAppId = 0;
                renderSkeleton("请先在“主页”添加或选择一个应用。源码示例按应用独立管理。");
            } else {
                loadCategories();
            }
        }));
    }

    private void loadCategories() {
        if (!permissionAllowed) return;
        long appId = app().session().selectedAppId();
        loadedAppId = appId;
        Map<String, String> query = new LinkedHashMap<>();
        query.put("resource_type", "source_market");
        track(app().repository().get("/api/admin/apps/" + appId + "/resource-categories", query, result -> {
            if (binding == null || loadedAppId != appId || app().session().selectedAppId() != appId) return;
            if (handleFailure(result, binding.getRoot())) return;
            categories.clear();
            categories.addAll(result.objectItems());
            if (categoryId > 0 && !hasCategoryId(categoryId)) categoryId = 0;
            render();
            loadResources();
        }));
    }

    private void renderSkeleton(String message) {
        if (binding == null) return;
        binding.pageContent.removeAllViews();
        binding.pageContent.addView(ManagementPageUi.title(requireContext(), "源码示例"));
        binding.pageContent.addView(ManagementPageUi.body(requireContext(), message));
    }

    private void render() {
        if (binding == null) return;
        LinearLayout content = binding.pageContent;
        content.removeAllViews();
        content.addView(ManagementPageUi.title(requireContext(), "源码示例"));
        content.addView(ManagementPageUi.body(requireContext(), "按开发语言和独立功能模块筛选真实源码资源；源码审核、文件权限与下载记录仍使用统一资源系统。"));

        content.addView(ManagementPageUi.heading(requireContext(), "开发语言分类"));
        content.addView(chipScroller(true));
        content.addView(ManagementPageUi.heading(requireContext(), "功能模块"));
        content.addView(chipScroller(false));

        LinearLayout actions = ManagementPageUi.row(requireContext());
        MaterialButton categoriesButton = ManagementPageUi.button(requireContext(), "管理源码分类", R.drawable.ic_folder, false);
        categoriesButton.setOnClickListener(view -> host().openModule("resource_categories"));
        ManagementPageUi.addWeighted(actions, categoriesButton, ManagementPageUi.dp(requireContext(), 8));
        MaterialButton resourcesButton = ManagementPageUi.button(requireContext(), "审核全部源码", R.drawable.ic_content, false);
        resourcesButton.setOnClickListener(view -> host().openModule("resources"));
        actions.addView(resourcesButton, new LinearLayout.LayoutParams(0, -2, 1f));
        content.addView(actions);

        TextView listHeading = ManagementPageUi.heading(requireContext(), "示例列表");
        listHeading.setTag(R.id.management_source_list_heading);
        content.addView(listHeading);
        LinearLayout list = ManagementPageUi.column(requireContext(), 0);
        list.setTag(R.id.management_source_list);
        list.addView(ManagementPageUi.body(requireContext(), "正在加载…"));
        content.addView(list);
    }

    private View chipScroller(boolean language) {
        HorizontalScrollView scroll = new HorizontalScrollView(requireContext());
        scroll.setHorizontalScrollBarEnabled(false);
        LinearLayout row = ManagementPageUi.row(requireContext());
        if (language) {
            Chip all = ManagementPageUi.chip(requireContext(), "全部语言");
            all.setChecked(categoryId == 0);
            all.setOnClickListener(view -> { categoryId = 0; render(); loadResources(); });
            row.addView(all);
            for (String name : ManagementNavigationPolicy.sourceCategories()) {
                JsonObject category = findCategory(name);
                long id = category == null ? 0 : Jsons.longValue(category, "id");
                Chip chip = ManagementPageUi.chip(requireContext(), name);
                chip.setChecked(id > 0 && categoryId == id);
                chip.setEnabled(id > 0);
                chip.setContentDescription(id > 0 ? name : name + "，当前应用尚未配置该分类");
                if (id > 0) {
                    chip.setOnClickListener(view -> { categoryId = id; render(); loadResources(); });
                }
                row.addView(chip);
            }
        } else {
            for (String[] item : MODULE_FILTERS) {
                Chip chip = ManagementPageUi.chip(requireContext(), item[0]);
                chip.setChecked(keyword.equals(item[1]));
                chip.setOnClickListener(view -> { keyword = item[1]; render(); loadResources(); });
                row.addView(chip);
            }
        }
        scroll.addView(row, new HorizontalScrollView.LayoutParams(-2, -2));
        return scroll;
    }

    @Nullable private JsonObject findCategory(String name) {
        for (JsonObject category : categories) {
            if (name.equals(Jsons.string(category, "name"))) return category;
        }
        return null;
    }

    private boolean hasCategoryId(long id) {
        for (JsonObject category : categories) if (Jsons.longValue(category, "id") == id) return true;
        return false;
    }

    private void loadResources() {
        if (binding == null || !permissionAllowed || app().session().selectedAppId() == 0) return;
        long appId = app().session().selectedAppId();
        Map<String, String> query = new LinkedHashMap<>();
        query.put("page", "1");
        query.put("limit", "30");
        query.put("resource_type", "source_market");
        if (categoryId > 0) query.put("category_id", Long.toString(categoryId));
        if (!keyword.isEmpty()) query.put("keyword", keyword);
        track(app().repository().get("/api/admin/apps/" + appId + "/resources", query, result -> {
            if (binding == null || loadedAppId != appId || app().session().selectedAppId() != appId) return;
            if (handleFailure(result, binding.getRoot())) return;
            LinearLayout list = binding.getRoot().findViewWithTag(R.id.management_source_list);
            if (list == null) return;
            list.removeAllViews();
            List<JsonObject> items = result.objectItems();
            if (items.isEmpty()) {
                TextView empty = ManagementPageUi.body(requireContext(), "当前筛选下暂无源码示例。用户提交后会进入审核列表。 ");
                int padding = ManagementPageUi.dp(requireContext(), 16);
                empty.setPadding(padding, padding, padding, padding);
                list.addView(ManagementPageUi.card(requireContext(), empty));
                return;
            }
            for (JsonObject item : items) list.addView(resourceCard(item));
        }));
    }

    private View resourceCard(JsonObject item) {
        LinearLayout box = ManagementPageUi.column(requireContext(), ManagementPageUi.dp(requireContext(), 14));
        box.addView(ManagementPageUi.title(requireContext(), Jsons.string(item, "title")));
        String category = Jsons.string(item, "category_name");
        String state = Jsons.string(item, "audit_status_label");
        if (state.isEmpty()) state = Jsons.string(item, "audit_status");
        box.addView(ManagementPageUi.body(requireContext(), "分类：" + (category.isEmpty() ? "未分类" : category)
            + " · 审核：" + state + "\n" + Jsons.string(item, "description")));
        MaterialButton open = ManagementPageUi.button(requireContext(), "查看与审核", R.drawable.ic_chevron_right, false);
        open.setOnClickListener(view -> host().openModule("resources"));
        box.addView(open);
        return ManagementPageUi.card(requireContext(), box);
    }

    @Override public void onDestroyView() {
        binding = null;
        categories.clear();
        loadedAppId = -1;
        permissionResolved = false;
        permissionAllowed = false;
        super.onDestroyView();
    }
}
