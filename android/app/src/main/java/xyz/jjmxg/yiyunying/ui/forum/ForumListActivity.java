package xyz.jjmxg.yiyunying.ui.forum;

import android.app.DatePickerDialog;
import android.content.Context;
import android.content.Intent;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.text.Editable;
import android.text.InputType;
import android.text.TextWatcher;
import android.view.LayoutInflater;
import android.view.MenuItem;
import android.view.View;
import android.view.ViewGroup;
import android.widget.LinearLayout;

import androidx.annotation.NonNull;
import androidx.appcompat.app.AlertDialog;
import androidx.recyclerview.widget.LinearLayoutManager;
import androidx.recyclerview.widget.RecyclerView;

import com.google.android.material.chip.Chip;
import xyz.jjmxg.yiyunying.ui.common.YiyunyingDialogBuilder;
import com.google.android.material.snackbar.Snackbar;
import com.google.android.material.textfield.TextInputEditText;
import com.google.android.material.textfield.TextInputLayout;
import com.google.gson.JsonArray;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;

import java.util.ArrayList;
import java.util.Calendar;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Locale;
import java.util.Map;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.core.AppAccess;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.data.api.RequestHandle;
import xyz.jjmxg.yiyunying.databinding.ActivityForumListBinding;
import xyz.jjmxg.yiyunying.databinding.ItemForumBinding;
import xyz.jjmxg.yiyunying.domain.Role;
import xyz.jjmxg.yiyunying.ui.auth.LoginActivity;
import xyz.jjmxg.yiyunying.ui.common.GlassActionDialog;
import xyz.jjmxg.yiyunying.ui.common.SafeTextInput;
import xyz.jjmxg.yiyunying.ui.common.ImageLoader;
import xyz.jjmxg.yiyunying.ui.common.RecordDetailDialog;

public final class ForumListActivity extends xyz.jjmxg.yiyunying.ui.common.SystemInsetActivity {
    private static final String EXTRA_PLATE_ID = "plate_id";
    private static final String EXTRA_PLATE_NAME = "plate_name";
    private static final String EXTRA_KEYWORD = "keyword";
    private static final String EXTRA_APP_ID = "app_id";
    private static final String EXTRA_APP_NAME = "app_name";

    private final Handler handler = new Handler(Looper.getMainLooper());
    private final Runnable delayedSearch = this::load;
    private final List<JsonObject> categories = new ArrayList<>();
    private final List<JsonObject> tags = new ArrayList<>();
    private ActivityForumListBinding binding;
    private ForumAdapter adapter;
    private RequestHandle request;
    private RequestHandle taxonomyRequest;
    private RequestHandle actionRequest;
    private long plateId;
    private long appId;
    private long selectedCategoryId;
    private Role role;
    private boolean showingPosts;
    private boolean refreshOnResume;
    private String initialKeyword = "";
    private String selectedTag = "";
    private String dateFrom = "";
    private String dateTo = "";

    public static void open(Context context) {
        context.startActivity(new Intent(context, ForumListActivity.class));
    }

    public static void openForApp(Context context, long appId, String appName) {
        context.startActivity(new Intent(context, ForumListActivity.class)
            .putExtra(EXTRA_APP_ID, appId).putExtra(EXTRA_APP_NAME, appName));
    }

    static void openPlate(Context context, long appId, long plateId, String plateName) {
        context.startActivity(new Intent(context, ForumListActivity.class)
            .putExtra(EXTRA_APP_ID, appId)
            .putExtra(EXTRA_PLATE_ID, plateId)
            .putExtra(EXTRA_PLATE_NAME, plateName));
    }

    public static void search(Context context, String keyword) {
        context.startActivity(new Intent(context, ForumListActivity.class).putExtra(EXTRA_KEYWORD, keyword));
    }

    public static void search(Context context, long appId, String keyword) {
        context.startActivity(new Intent(context, ForumListActivity.class)
            .putExtra(EXTRA_APP_ID, appId).putExtra(EXTRA_KEYWORD, keyword));
    }

    @Override protected void onCreate(Bundle state) {
        super.onCreate(state);
        if (!AppAccess.from(this).session().isAuthenticated()) { login(); return; }
        role = AppAccess.from(this).session().role();
        appId = getIntent().getLongExtra(EXTRA_APP_ID, 0);
        if (appId <= 0 && role == Role.ADMIN) appId = AppAccess.from(this).session().selectedAppId();
        if (role != Role.USER && appId <= 0) {
            Snackbar.make(findViewById(android.R.id.content), "请先选择需要管理的应用", Snackbar.LENGTH_LONG).show();
            finish();
            return;
        }
        plateId = getIntent().getLongExtra(EXTRA_PLATE_ID, 0);
        initialKeyword = getIntent().getStringExtra(EXTRA_KEYWORD);
        if (initialKeyword == null) initialKeyword = "";
        showingPosts = plateId > 0 || !initialKeyword.isEmpty();

        binding = ActivityForumListBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());
        String appName = getIntent().getStringExtra(EXTRA_APP_NAME);
        String title = plateId > 0 ? getIntent().getStringExtra(EXTRA_PLATE_NAME) : (showingPosts ? "帖子搜索" : "论坛社区");
        binding.toolbar.setTitle(role == Role.USER || appName == null || appName.isEmpty() ? title : appName + " · " + title);
        binding.toolbar.setNavigationOnClickListener(view -> finish());
        binding.searchLayout.setHint(showingPosts ? "搜索标题、正文、标签或帖子编号" : "搜索论坛板块");
        binding.searchInput.setText(initialKeyword);
        binding.taxonomyArea.setVisibility(plateId > 0 ? View.VISIBLE : View.GONE);
        binding.filterScroller.setVisibility(showingPosts ? View.VISIBLE : View.GONE);
        binding.structureRequestButton.setVisibility(role == Role.USER && (!showingPosts || plateId > 0) ? View.VISIBLE : View.GONE);
        binding.structureRequestButton.setText(plateId > 0 ? "申请分类、标签或版主" : "申请板块");
        binding.structureRequestButton.setOnClickListener(view -> showStructureRequestMenu());

        binding.startDateButton.setOnClickListener(view -> pickDate(true));
        binding.endDateButton.setOnClickListener(view -> pickDate(false));
        binding.clearFilterButton.setOnClickListener(view -> {
            dateFrom = "";
            dateTo = "";
            binding.startDateButton.setText("开始日期");
            binding.endDateButton.setText("结束日期");
            load();
        });
        binding.createButton.setVisibility(role == Role.USER && plateId > 0 ? View.VISIBLE : View.GONE);
        binding.createButton.setOnClickListener(view -> createPost());
        if (role == Role.USER && plateId > 0) {
            MenuItem create = binding.toolbar.getMenu().add("发布帖子");
            create.setIcon(R.drawable.ic_add);
            create.setShowAsAction(MenuItem.SHOW_AS_ACTION_ALWAYS);
            create.setOnMenuItemClickListener(item -> { createPost(); return true; });
        }
        if (role == Role.ADMIN) {
            MenuItem manage = binding.toolbar.getMenu().add("论坛结构管理");
            manage.setIcon(R.drawable.ic_settings);
            manage.setShowAsAction(MenuItem.SHOW_AS_ACTION_ALWAYS);
            manage.setOnMenuItemClickListener(item -> {
                showAdminStructureMenu();
                return true;
            });
        }

        adapter = new ForumAdapter(showingPosts, new ForumAdapter.Listener() {
            @Override public void onClick(JsonObject item) { openItem(item); }
            @Override public void onLongPress(JsonObject item) { showListActions(item, showingPosts); }
        });
        binding.recycler.setLayoutManager(new LinearLayoutManager(this));
        binding.recycler.setHasFixedSize(true);
        binding.recycler.setItemViewCacheSize(8);
        binding.recycler.setItemAnimator(null);
        binding.recycler.setAdapter(adapter);
        binding.swipeRefresh.setOnRefreshListener(this::refreshAll);
        binding.searchInput.addTextChangedListener(new TextWatcher() {
            @Override public void beforeTextChanged(CharSequence value, int start, int count, int after) { }
            @Override public void onTextChanged(CharSequence value, int start, int before, int count) {
                handler.removeCallbacks(delayedSearch);
                handler.postDelayed(delayedSearch, 320L);
            }
            @Override public void afterTextChanged(Editable value) { }
        });
        refreshAll();
    }

    private boolean isUiActive() {
        return binding != null && !isFinishing() && !isDestroyed();
    }

    private void refreshAll() {
        if (!isUiActive()) return;
        load();
        if (plateId > 0 && categories.isEmpty()) loadCategories();
        else if (plateId > 0) loadTags();
    }

    private void load() {
        if (!isUiActive()) return;
        handler.removeCallbacks(delayedSearch);
        if (request != null) request.cancel();
        binding.progress.setVisibility(View.VISIBLE);
        Map<String, String> query = new LinkedHashMap<>();
        String path;
        String keyword = text(binding.searchInput);
        if (showingPosts) {
            path = forumPrefix() + "/forum-posts";
            if (plateId > 0) query.put("plate_id", String.valueOf(plateId));
            if (selectedCategoryId > 0) query.put("category_id", String.valueOf(selectedCategoryId));
            if (!selectedTag.isEmpty()) query.put("tag", selectedTag);
            query.put("limit", "100");
            if (!keyword.isEmpty()) query.put("keyword", keyword);
            if (!dateFrom.isEmpty()) query.put("date_from", dateFrom);
            if (!dateTo.isEmpty()) query.put("date_to", dateTo);
        } else {
            path = forumPrefix() + "/forum-plates";
            if (!keyword.isEmpty()) query.put("keyword", keyword);
        }
        AppAccess.from(this).repository().getCached(path, query, cached -> {
            if (binding == null || isFinishing() || isDestroyed() || !cached.isSuccessful()) return;
            List<JsonObject> cachedItems = objects(cached.items());
            adapter.submit(cachedItems);
            binding.emptyText.setVisibility(cachedItems.isEmpty() ? View.VISIBLE : View.GONE);
            binding.progress.setVisibility(View.INVISIBLE);
        });
        request = AppAccess.from(this).repository().get(path, query, result -> {
            request = null;
            if (!isUiActive()) return;
            binding.progress.setVisibility(View.INVISIBLE);
            binding.swipeRefresh.setRefreshing(false);
            if (result.isAuthenticationFailure()) { login(); return; }
            if (!result.isSuccessful()) {
                Snackbar.make(binding.getRoot(), result.message().isEmpty() ? "论坛加载失败" : result.message(), Snackbar.LENGTH_LONG).show();
                return;
            }
            List<JsonObject> items = objects(result.items());
            adapter.submit(items);
            binding.emptyText.setVisibility(items.isEmpty() ? View.VISIBLE : View.GONE);
            binding.emptyText.setText(showingPosts ? "没有找到符合条件的帖子" : "还没有符合条件的论坛板块");
        });
    }

    private void loadCategories() {
        if (taxonomyRequest != null) taxonomyRequest.cancel();
        Map<String, String> query = new LinkedHashMap<>();
        query.put("plate_id", String.valueOf(plateId));
        taxonomyRequest = AppAccess.from(this).repository().get(forumPrefix() + "/forum-categories", query, result -> {
            taxonomyRequest = null;
            if (!isUiActive() || !result.isSuccessful()) return;
            categories.clear();
            categories.addAll(objects(result.items()));
            if (selectedCategoryId > 0 && findById(categories, selectedCategoryId) == null) selectedCategoryId = 0;
            renderCategoryChips();
            loadTags();
        });
    }

    private void loadTags() {
        if (taxonomyRequest != null) taxonomyRequest.cancel();
        Map<String, String> query = new LinkedHashMap<>();
        query.put("plate_id", String.valueOf(plateId));
        if (selectedCategoryId > 0) query.put("category_id", String.valueOf(selectedCategoryId));
        taxonomyRequest = AppAccess.from(this).repository().get(forumPrefix() + "/forum-tags", query, result -> {
            taxonomyRequest = null;
            if (!isUiActive() || !result.isSuccessful()) return;
            tags.clear();
            tags.addAll(objects(result.items()));
            if (!selectedTag.isEmpty() && findByName(tags, selectedTag) == null) selectedTag = "";
            renderTagChips();
        });
    }

    private void renderCategoryChips() {
        binding.categoryChips.removeAllViews();
        binding.categoryChips.addView(filterChip("全部", selectedCategoryId == 0, () -> selectCategory(0)));
        for (JsonObject category : categories) {
            long id = Jsons.longValue(category, "id");
            String label = Jsons.string(category, "name");
            long count = Jsons.longValue(category, "post_count");
            binding.categoryChips.addView(filterChip(label + (count > 0 ? " " + count : ""), selectedCategoryId == id, () -> selectCategory(id)));
        }
    }

    private void renderTagChips() {
        binding.tagChips.removeAllViews();
        binding.tagChips.addView(filterChip("全部标签", selectedTag.isEmpty(), () -> selectTag("")));
        for (JsonObject tag : tags) {
            String name = Jsons.string(tag, "name");
            binding.tagChips.addView(filterChip("#" + name, selectedTag.equals(name), () -> selectTag(name)));
        }
    }

    private Chip filterChip(String label, boolean checked, Runnable action) {
        Chip chip = new Chip(this);
        chip.setText(label);
        chip.setCheckable(true);
        chip.setChecked(checked);
        chip.setEnsureMinTouchTargetSize(false);
        chip.setOnClickListener(view -> action.run());
        return chip;
    }

    private void selectCategory(long categoryId) {
        if (selectedCategoryId == categoryId) return;
        selectedCategoryId = categoryId;
        selectedTag = "";
        renderCategoryChips();
        loadTags();
        load();
    }

    private void selectTag(String tag) {
        if (selectedTag.equals(tag)) return;
        selectedTag = tag;
        renderTagChips();
        load();
    }

    private void pickDate(boolean start) {
        Calendar now = Calendar.getInstance();
        new DatePickerDialog(this, (picker, year, month, day) -> {
            String value = String.format(Locale.CHINA, "%04d-%02d-%02d", year, month + 1, day);
            if (start) {
                dateFrom = value;
                binding.startDateButton.setText("从 " + value);
            } else {
                dateTo = value;
                binding.endDateButton.setText("到 " + value);
            }
            load();
        }, now.get(Calendar.YEAR), now.get(Calendar.MONTH), now.get(Calendar.DAY_OF_MONTH)).show();
    }

    private void openItem(JsonObject item) {
        if (!isUiActive()) return;
        if (showingPosts) {
            ForumPostActivity.open(this, appId, Jsons.longValue(item, "id"));
        } else {
            ForumListActivity.openPlate(this, appId, Jsons.longValue(item, "id"), Jsons.string(item, "name"));
        }
    }

    private void createPost() {
        if (!isUiActive() || plateId <= 0) return;
        refreshOnResume = true;
        ForumComposerActivity.createPost(this, plateId, selectedCategoryId);
    }

    private void showListActions(JsonObject item, boolean post) {
        if (!isUiActive()) return;
        if (role != Role.USER) {
            showForumInfo(item, post);
            return;
        }
        List<GlassActionDialog.Action> actions = new ArrayList<>();
        actions.add(new GlassActionDialog.Action(post ? "帖子信息" : "板块信息", post ? R.drawable.ic_content : R.drawable.ic_forum,
            () -> showForumInfo(item, post)));
        actions.add(new GlassActionDialog.Action("个人置顶", R.drawable.ic_more,
            () -> savePersonalPosition(item, post, "top")));
        actions.add(new GlassActionDialog.Action("个人置底", R.drawable.ic_more,
            () -> savePersonalPosition(item, post, "bottom")));
        actions.add(new GlassActionDialog.Action("恢复默认", R.drawable.ic_refresh,
            () -> savePersonalPosition(item, post, "normal")));
        GlassActionDialog.show(this, post ? "帖子操作" : "板块操作", actions);
    }

    private void showForumInfo(JsonObject item, boolean post) {
        if (!isUiActive()) return;
        String title = Jsons.string(item, post ? "title" : "name");
        JsonObject detail = new JsonObject();
        if (post) {
            detail.addProperty("所属板块", Jsons.string(item, "plate_name"));
            detail.addProperty("二级分类", fallback(Jsons.string(item, "category_name"), "未分类"));
            detail.addProperty("发布者", fallback(Jsons.string(item, "nickname"), "用户"));
            detail.addProperty("阅读", Jsons.longValue(item, "unique_view_count") + " 人");
            detail.addProperty("互动", Jsons.longValue(item, "like_count") + " 赞 · " + Jsons.longValue(item, "comment_count") + " 评论");
            if (flag(item, "paid_content")) detail.addProperty("内容权限", "包含余额解锁内容");
            long sections = Jsons.longValue(item, "section_count");
            if (sections > 0) detail.addProperty("内容结构", sections + " 个内容节");
            String tagText = joinTags(Jsons.array(item, "tags"));
            if (!tagText.isEmpty()) detail.addProperty("标签", "#" + tagText);
        } else {
            detail.addProperty("二级分类", Jsons.longValue(item, "category_count") + " 个");
            detail.addProperty("帖子", Jsons.longValue(item, "post_count") + " 篇");
            detail.addProperty("说明", fallback(Jsons.string(item, "description"), "暂无板块说明"));
        }
        RecordDetailDialog.show(this, title, detail, post ? "查看帖子" : "进入板块", () -> openItem(item));
    }

    private void showStructureRequestMenu() {
        if (!isUiActive() || role != Role.USER) return;
        if (plateId <= 0) {
            showStructureRequestForm("plate");
            return;
        }
        List<GlassActionDialog.Action> actions = new ArrayList<>();
        actions.add(new GlassActionDialog.Action("申请二级分类", R.drawable.ic_add,
            () -> showStructureRequestForm("category")));
        actions.add(new GlassActionDialog.Action("申请规范标签", R.drawable.ic_forum,
            () -> showStructureRequestForm("tag")));
        actions.add(new GlassActionDialog.Action("申请成为版主", R.drawable.ic_person,
            () -> showStructureRequestForm("moderator")));
        GlassActionDialog.show(this, "申请论坛权限或结构", actions);
    }

    private void showAdminStructureMenu() {
        if (!isUiActive() || role != Role.ADMIN) return;
        List<String> labels = new ArrayList<>();
        List<Runnable> actions = new ArrayList<>();
        if (plateId > 0) {
            labels.add("新建二级分类");
            actions.add(() -> showAdminCategoryForm(null));
            labels.add("管理二级分类");
            actions.add(this::showAdminCategories);
            labels.add("新建规范标签");
            actions.add(() -> showAdminTagForm(null));
            labels.add(selectedCategoryId > 0 ? "管理当前分类标签" : "管理全部标签");
            actions.add(this::showAdminTags);
        }
        labels.add("审核板块、分类和标签申请");
        actions.add(this::showAdminRequests);
        new YiyunyingDialogBuilder(this)
            .setTitle("论坛结构管理")
            .setItems(labels.toArray(new String[0]), (dialog, which) -> actions.get(which).run())
            .setNegativeButton("关闭", null)
            .show();
    }

    private void showAdminCategories() {
        if (!isUiActive()) return;
        if (categories.isEmpty()) {
            Snackbar.make(binding.getRoot(), "当前板块还没有二级分类，可先创建一个", Snackbar.LENGTH_LONG).show();
            return;
        }
        String[] labels = new String[categories.size()];
        for (int i = 0; i < categories.size(); i++) {
            JsonObject item = categories.get(i);
            labels[i] = Jsons.string(item, "name") + "  ·  " + Jsons.longValue(item, "post_count") + " 篇帖子";
        }
        new YiyunyingDialogBuilder(this)
            .setTitle("管理二级分类")
            .setItems(labels, (dialog, which) -> showAdminCategoryActions(categories.get(which)))
            .setNegativeButton("关闭", null)
            .show();
    }

    private void showAdminTags() {
        if (!isUiActive()) return;
        if (tags.isEmpty()) {
            Snackbar.make(binding.getRoot(), "当前范围还没有规范标签，可先创建一个", Snackbar.LENGTH_LONG).show();
            return;
        }
        String[] labels = new String[tags.size()];
        for (int i = 0; i < tags.size(); i++) {
            JsonObject item = tags.get(i);
            String category = Jsons.string(item, "category_name");
            labels[i] = "#" + Jsons.string(item, "name") + (category.isEmpty() ? "" : "  ·  " + category);
        }
        new YiyunyingDialogBuilder(this)
            .setTitle(selectedCategoryId > 0 ? "管理当前分类标签" : "管理全部标签")
            .setItems(labels, (dialog, which) -> showAdminTagActions(tags.get(which)))
            .setNegativeButton("关闭", null)
            .show();
    }

    private void showAdminCategoryActions(JsonObject category) {
        if (!isUiActive()) return;
        new YiyunyingDialogBuilder(this)
            .setBusinessTitle(Jsons.string(category, "name"))
            .setItems(new String[]{"修改分类", "删除分类"}, (dialog, which) -> {
                if (which == 0) showAdminCategoryForm(category);
                else confirmAdminDelete(category, false);
            })
            .setNegativeButton("取消", null)
            .show();
    }

    private void showAdminTagActions(JsonObject tag) {
        if (!isUiActive()) return;
        new YiyunyingDialogBuilder(this)
            .setBusinessTitle("#" + Jsons.string(tag, "name"))
            .setItems(new String[]{"修改标签", "删除标签"}, (dialog, which) -> {
                if (which == 0) showAdminTagForm(tag);
                else confirmAdminDelete(tag, true);
            })
            .setNegativeButton("取消", null)
            .show();
    }

    private void showAdminCategoryForm(JsonObject existing) {
        if (!isUiActive()) return;
        LinearLayout form = dialogForm();
        TextInputEditText name = addField(form, "分类名称", false);
        TextInputEditText description = addField(form, "分类说明", true);
        TextInputEditText sort = addField(form, "排序值，数字越大越靠前", false);
        sort.setInputType(InputType.TYPE_CLASS_NUMBER | InputType.TYPE_NUMBER_FLAG_SIGNED);
        if (existing != null) {
            name.setText(Jsons.string(existing, "name"));
            description.setText(Jsons.string(existing, "description"));
            sort.setText(String.valueOf(Jsons.longValue(existing, "sort_order")));
        }
        AlertDialog dialog = new YiyunyingDialogBuilder(this)
            .setTitle(existing == null ? "新建二级分类" : "修改二级分类")
            .setView(form)
            .setPositiveButton("保存", null)
            .setNegativeButton("取消", null)
            .create();
        dialog.setOnShowListener(ignored -> dialog.getButton(AlertDialog.BUTTON_POSITIVE).setOnClickListener(view -> {
            if (text(name).isEmpty()) { name.setError("请填写分类名称"); return; }
            JsonObject body = new JsonObject();
            body.addProperty("plate_id", plateId);
            body.addProperty("name", text(name));
            body.addProperty("description", text(description));
            body.addProperty("sort_order", integer(text(sort)));
            String path = forumPrefix() + "/forum-categories" + (existing == null ? "" : "/" + Jsons.longValue(existing, "id"));
            executeAdmin(existing == null ? "post" : "put", path, body, existing == null ? "分类已创建" : "分类已修改", () -> {
                dialog.dismiss();
                loadCategories();
            });
        }));
        dialog.show();
    }

    private void showAdminTagForm(JsonObject existing) {
        if (!isUiActive()) return;
        LinearLayout form = dialogForm();
        TextInputEditText name = addField(form, "标签名称", false);
        TextInputEditText aliases = addField(form, "同义词，用逗号分隔，例如 MC, Minecraft, 麦块", false);
        TextInputEditText description = addField(form, "标签说明", true);
        TextInputEditText sort = addField(form, "排序值，数字越大越靠前", false);
        sort.setInputType(InputType.TYPE_CLASS_NUMBER | InputType.TYPE_NUMBER_FLAG_SIGNED);
        if (existing != null) {
            name.setText(Jsons.string(existing, "name"));
            aliases.setText(joinPrimitive(Jsons.array(existing, "aliases"), ", "));
            description.setText(Jsons.string(existing, "description"));
            sort.setText(String.valueOf(Jsons.longValue(existing, "sort_order")));
        }
        AlertDialog dialog = new YiyunyingDialogBuilder(this)
            .setTitle(existing == null ? "新建规范标签" : "修改规范标签")
            .setView(form)
            .setPositiveButton("保存", null)
            .setNegativeButton("取消", null)
            .create();
        dialog.setOnShowListener(ignored -> dialog.getButton(AlertDialog.BUTTON_POSITIVE).setOnClickListener(view -> {
            if (text(name).isEmpty()) { name.setError("请填写标签名称"); return; }
            JsonObject body = new JsonObject();
            body.addProperty("plate_id", plateId);
            if (selectedCategoryId > 0) body.addProperty("category_id", selectedCategoryId);
            else if (existing != null && Jsons.longValue(existing, "category_id") > 0) body.addProperty("category_id", Jsons.longValue(existing, "category_id"));
            body.addProperty("name", text(name));
            body.addProperty("description", text(description));
            body.addProperty("sort_order", integer(text(sort)));
            JsonArray aliasValues = new JsonArray();
            for (String alias : text(aliases).split("[,，]")) if (!alias.trim().isEmpty()) aliasValues.add(alias.trim());
            body.add("aliases", aliasValues);
            String path = forumPrefix() + "/forum-tags" + (existing == null ? "" : "/" + Jsons.longValue(existing, "id"));
            executeAdmin(existing == null ? "post" : "put", path, body, existing == null ? "标签已创建" : "标签已修改", () -> {
                dialog.dismiss();
                loadTags();
            });
        }));
        dialog.show();
    }

    private void confirmAdminDelete(JsonObject item, boolean tag) {
        if (!isUiActive()) return;
        String name = Jsons.string(item, "name");
        new YiyunyingDialogBuilder(this)
            .setTitle("删除“" + name + "”？")
            .setMessage(tag ? "标签会从可选项中移除，已有帖子的文字标签仍会保留。" : "分类删除后，已有帖子会保留并转为未分类。")
            .setPositiveButton("删除", (dialog, which) -> {
                String path = forumPrefix() + (tag ? "/forum-tags/" : "/forum-categories/") + Jsons.longValue(item, "id");
                executeAdmin("delete", path, new JsonObject(), tag ? "标签已删除" : "分类已删除", tag ? this::loadTags : this::loadCategories);
            })
            .setNegativeButton("取消", null)
            .show();
    }

    private void showAdminRequests() {
        if (!isUiActive() || actionRequest != null) return;
        Map<String, String> query = new LinkedHashMap<>();
        query.put("status", "pending");
        query.put("limit", "100");
        actionRequest = AppAccess.from(this).repository().get(forumPrefix() + "/forum-structure-requests", query, result -> {
            actionRequest = null;
            if (!isUiActive()) return;
            if (!result.isSuccessful()) {
                Snackbar.make(binding.getRoot(), fallback(result.message(), "申请列表加载失败"), Snackbar.LENGTH_LONG).show();
                return;
            }
            List<JsonObject> requests = objects(result.items());
            if (requests.isEmpty()) {
                Snackbar.make(binding.getRoot(), "当前没有待审核申请", Snackbar.LENGTH_LONG).show();
                return;
            }
            String[] labels = new String[requests.size()];
            for (int i = 0; i < requests.size(); i++) {
                JsonObject item = requests.get(i);
                labels[i] = requestType(Jsons.string(item, "request_type")) + " · " + Jsons.string(item, "name")
                    + "\n申请人：" + fallback(Jsons.string(item, "nickname"), Jsons.string(item, "account"));
            }
            new YiyunyingDialogBuilder(this)
                .setTitle("待审核创建申请")
                .setItems(labels, (dialog, which) -> showAdminRequest(requests.get(which)))
                .setNegativeButton("关闭", null)
                .show();
        });
    }

    private void showAdminRequest(JsonObject item) {
        if (!isUiActive()) return;
        JsonObject detail = new JsonObject();
        detail.addProperty("申请类型", requestType(Jsons.string(item, "request_type")));
        detail.addProperty("名称", Jsons.string(item, "name"));
        detail.addProperty("申请人", fallback(Jsons.string(item, "nickname"), Jsons.string(item, "account")));
        detail.addProperty("所属板块", Jsons.string(item, "plate_name"));
        detail.addProperty("目标分类", Jsons.string(item, "category_name"));
        detail.addProperty("同义词", joinPrimitive(Jsons.array(item, "aliases"), "、"));
        detail.addProperty("用途说明", Jsons.string(item, "description"));
        detail.addProperty("申请理由", Jsons.string(item, "reason"));
        RecordDetailDialog.showDecision(this,
            "审核“" + Jsons.string(item, "name") + "”",
            detail,
            "拒绝", () -> reviewAdminRequest(item, "reject"),
            "通过并创建", () -> reviewAdminRequest(item, "approve"));
    }

    private void reviewAdminRequest(JsonObject item, String decision) {
        if (!isUiActive()) return;
        LinearLayout form = dialogForm();
        TextInputEditText comment = addField(form, "审核说明（可选）", true);
        AlertDialog dialog = new YiyunyingDialogBuilder(this)
            .setTitle("approve".equals(decision) ? "确认通过并自动创建" : "确认拒绝申请")
            .setView(form)
            .setPositiveButton("确认", null)
            .setNegativeButton("取消", null)
            .create();
        dialog.setOnShowListener(ignored -> dialog.getButton(AlertDialog.BUTTON_POSITIVE).setOnClickListener(view -> {
            JsonObject body = new JsonObject();
            body.addProperty("decision", decision);
            body.addProperty("review_comment", text(comment));
            executeAdmin("post", forumPrefix() + "/forum-structure-requests/" + Jsons.longValue(item, "id") + "/review", body, "审核已完成", () -> {
                dialog.dismiss();
                refreshAll();
            });
        }));
        dialog.show();
    }

    private void executeAdmin(String method, String path, JsonObject body, String success, Runnable finished) {
        if (!isUiActive() || actionRequest != null) return;
        if ("put".equals(method)) actionRequest = AppAccess.from(this).repository().put(path, body, result -> finishAdminAction(result, success, finished));
        else if ("delete".equals(method)) actionRequest = AppAccess.from(this).repository().delete(path, body, result -> finishAdminAction(result, success, finished));
        else actionRequest = AppAccess.from(this).repository().post(path, body, result -> finishAdminAction(result, success, finished));
    }

    private void finishAdminAction(xyz.jjmxg.yiyunying.data.api.ApiResult result, String success, Runnable finished) {
        actionRequest = null;
        if (!isUiActive()) return;
        if (result.isAuthenticationFailure()) { login(); return; }
        Snackbar.make(binding.getRoot(), result.isSuccessful() ? fallback(result.message(), success) : fallback(result.message(), "操作失败"), Snackbar.LENGTH_LONG).show();
        if (result.isSuccessful()) finished.run();
    }

    private LinearLayout dialogForm() {
        LinearLayout form = new LinearLayout(this);
        form.setOrientation(LinearLayout.VERTICAL);
        form.setPadding(dp(20), dp(8), dp(20), 0);
        return form;
    }

    private void showStructureRequestForm(String type) {
        if (!isUiActive()) return;
        LinearLayout form = dialogForm();
        TextInputEditText name = addField(form, "名称", false);
        boolean moderatorRequest = "moderator".equals(type);
        if (moderatorRequest) {
            name.setText("版主申请");
            View parent = (View) name.getParent();
            if (parent != null) parent.setVisibility(View.GONE);
        }
        TextInputEditText aliases = null;
        if ("tag".equals(type)) aliases = addField(form, "同义词，用逗号分隔（例如 MC, Minecraft, 麦块）", false);
        TextInputEditText description = addField(form, moderatorRequest ? "管理经验与计划" : "用途说明", true);
        TextInputEditText reason = addField(form, moderatorRequest ? "申请理由（建议填写）" : "申请理由", true);
        String label = "plate".equals(type) ? "板块"
            : ("category".equals(type) ? "二级分类" : ("moderator".equals(type) ? "版主" : "规范标签"));
        if ("tag".equals(type) && selectedCategoryId > 0) {
            JsonObject selected = findById(categories, selectedCategoryId);
            if (selected != null) description.setText("建议归入二级分类：" + Jsons.string(selected, "name"));
        }
        TextInputEditText finalAliases = aliases;
        AlertDialog dialog = new YiyunyingDialogBuilder(this)
            .setTitle("申请新" + label)
            .setView(form)
            .setPositiveButton("提交申请", null)
            .setNegativeButton("取消", null)
            .create();
        dialog.setOnShowListener(ignored -> dialog.getButton(AlertDialog.BUTTON_POSITIVE).setOnClickListener(view -> {
            String requestName = moderatorRequest ? "版主申请" : text(name);
            if (requestName.isEmpty()) {
                name.setError("请填写名称");
                return;
            }
            submitStructureRequest(dialog, type, requestName, finalAliases == null ? "" : text(finalAliases), text(description), text(reason));
        }));
        dialog.show();
    }

    private TextInputEditText addField(LinearLayout form, String hint, boolean multiline) {
        TextInputLayout layout = new TextInputLayout(this);
        layout.setHint(hint);
        layout.setBoxBackgroundMode(TextInputLayout.BOX_BACKGROUND_OUTLINE);
        LinearLayout.LayoutParams params = new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT);
        params.topMargin = dp(8);
        layout.setLayoutParams(params);
        TextInputEditText input = new TextInputEditText(layout.getContext());
        input.setInputType(InputType.TYPE_CLASS_TEXT | (multiline ? InputType.TYPE_TEXT_FLAG_MULTI_LINE : 0));
        input.setMaxLines(multiline ? 3 : 1);
        input.setMinLines(multiline ? 2 : 1);
        SafeTextInput.attach(layout, input);
        form.addView(layout);
        return input;
    }

    private void submitStructureRequest(AlertDialog dialog, String type, String name, String aliasText, String description, String reason) {
        if (!isUiActive() || actionRequest != null) return;
        JsonObject body = new JsonObject();
        body.addProperty("request_type", type);
        body.addProperty("name", name);
        if (plateId > 0) body.addProperty("plate_id", plateId);
        if ("tag".equals(type) && selectedCategoryId > 0) body.addProperty("category_id", selectedCategoryId);
        body.addProperty("description", description);
        body.addProperty("reason", reason);
        JsonArray aliases = new JsonArray();
        for (String alias : aliasText.split("[,，]")) if (!alias.trim().isEmpty()) aliases.add(alias.trim());
        body.add("aliases", aliases);
        actionRequest = AppAccess.from(this).repository().post("/api/user/forum-structure-requests", body, result -> {
            actionRequest = null;
            if (!isUiActive()) return;
            if (result.isAuthenticationFailure()) { login(); return; }
            Snackbar.make(binding.getRoot(), result.isSuccessful() ? fallback(result.message(), "申请已提交") : fallback(result.message(), "申请提交失败"), Snackbar.LENGTH_LONG).show();
            if (result.isSuccessful()) dialog.dismiss();
        });
    }

    private String forumPrefix() {
        if (role == Role.USER) return "/api/user";
        return "/api/" + role.wireName() + "/apps/" + appId;
    }

    private void savePersonalPosition(JsonObject item, boolean post, String position) {
        if (!isUiActive() || request != null) return;
        JsonObject body = new JsonObject();
        body.addProperty("position", position);
        body.addProperty("sort_order", 0);
        binding.progress.setVisibility(View.VISIBLE);
        request = AppAccess.from(this).repository().put(
            "/api/user/forum-personal/" + (post ? "post" : "plate") + "/" + Jsons.longValue(item, "id") + "/position",
            body, result -> {
                request = null;
                if (!isUiActive()) return;
                binding.progress.setVisibility(View.INVISIBLE);
                Snackbar.make(binding.getRoot(), result.isSuccessful() ? "个人排序已保存" : result.message(), Snackbar.LENGTH_LONG).show();
                if (result.isSuccessful()) load();
            });
    }

    @Override protected void onResume() {
        super.onResume();
        if (refreshOnResume && binding != null) {
            refreshOnResume = false;
            load();
        }
    }

    private void login() {
        AppAccess.from(this).session().clearAuthentication();
        startActivity(new Intent(this, LoginActivity.class).putExtra(LoginActivity.EXTRA_FORCE_LOGIN, true)
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TASK));
        finish();
    }

    private static List<JsonObject> objects(JsonArray array) {
        List<JsonObject> result = new ArrayList<>();
        for (JsonElement element : array) if (element.isJsonObject()) result.add(element.getAsJsonObject());
        return result;
    }

    private static JsonObject findById(List<JsonObject> values, long id) {
        for (JsonObject value : values) if (Jsons.longValue(value, "id") == id) return value;
        return null;
    }

    private static JsonObject findByName(List<JsonObject> values, String name) {
        for (JsonObject value : values) if (name.equals(Jsons.string(value, "name"))) return value;
        return null;
    }

    private static String text(android.widget.EditText input) {
        return input.getText() == null ? "" : input.getText().toString().trim();
    }

    private static String fallback(String value, String fallback) {
        return value == null || value.isEmpty() ? fallback : value;
    }

    private static boolean flag(JsonObject object, String key) {
        try { return object.has(key) && !object.get(key).isJsonNull() && object.get(key).getAsBoolean(); }
        catch (RuntimeException ignored) { return false; }
    }

    private static void appendLine(StringBuilder target, String label, String value) {
        if (value != null && !value.isEmpty()) target.append(label).append("：").append(value).append('\n');
    }

    private static String joinTags(JsonArray tags) {
        List<String> values = new ArrayList<>();
        for (JsonElement element : tags) if (element.isJsonPrimitive()) values.add(element.getAsString());
        return String.join(" #", values);
    }

    private static String joinPrimitive(JsonArray values, String separator) {
        List<String> result = new ArrayList<>();
        for (JsonElement element : values) if (element.isJsonPrimitive()) result.add(element.getAsString());
        return String.join(separator, result);
    }

    private static int integer(String value) {
        try { return value == null || value.trim().isEmpty() ? 0 : Integer.parseInt(value.trim()); }
        catch (NumberFormatException ignored) { return 0; }
    }

    private static String requestType(String type) {
        if ("plate".equals(type)) return "板块";
        if ("category".equals(type)) return "二级分类";
        if ("tag".equals(type)) return "规范标签";
        if ("moderator".equals(type)) return "版主权限";
        return "论坛结构";
    }

    private int dp(int value) {
        return Math.round(value * getResources().getDisplayMetrics().density);
    }

    @Override protected void onDestroy() {
        handler.removeCallbacks(delayedSearch);
        if (request != null) request.cancel();
        if (taxonomyRequest != null) taxonomyRequest.cancel();
        if (actionRequest != null) actionRequest.cancel();
        binding = null;
        super.onDestroy();
    }

    private static final class ForumAdapter extends RecyclerView.Adapter<ForumAdapter.Holder> {
        interface Listener {
            void onClick(JsonObject item);
            void onLongPress(JsonObject item);
        }

        private final boolean posts;
        private final Listener listener;
        private final List<JsonObject> items = new ArrayList<>();

        ForumAdapter(boolean posts, Listener listener) {
            this.posts = posts;
            this.listener = listener;
            setHasStableIds(true);
        }

        void submit(List<JsonObject> next) {
            if (sameOrder(next)) {
                ArrayList<Integer> changed = new ArrayList<>();
                for (int index = 0; index < next.size(); index++) {
                    JsonObject value = next.get(index);
                    if (!items.get(index).equals(value)) {
                        items.set(index, value);
                        changed.add(index);
                    }
                }
                if (changed.isEmpty()) return;
                if (changed.size() > 12) {
                    notifyItemRangeChanged(0, items.size());
                } else {
                    for (int index : changed) notifyItemChanged(index);
                }
                return;
            }
            items.clear();
            items.addAll(next);
            notifyDataSetChanged();
        }

        private boolean sameOrder(List<JsonObject> next) {
            if (items.size() != next.size()) return false;
            for (int index = 0; index < next.size(); index++) {
                if (Jsons.longValue(items.get(index), "id") != Jsons.longValue(next.get(index), "id")) {
                    return false;
                }
            }
            return true;
        }

        @Override public long getItemId(int position) { return Jsons.longValue(items.get(position), "id"); }

        @NonNull @Override public Holder onCreateViewHolder(@NonNull ViewGroup parent, int viewType) {
            return new Holder(ItemForumBinding.inflate(LayoutInflater.from(parent.getContext()), parent, false));
        }

        @Override public void onBindViewHolder(@NonNull Holder holder, int position) {
            JsonObject item = items.get(position);
            String title = Jsons.string(item, posts ? "title" : "name");
            String subtitle = compact(Jsons.string(item, posts ? "content" : "description"));
            holder.binding.title.setText(title);
            holder.binding.subtitle.setText(fallback(subtitle, posts ? "暂无正文预览" : "暂无板块说明"));

            if (posts) bindPost(holder, item);
            else bindPlate(holder, item);
            bindAvatar(holder, item, title);

            holder.binding.getRoot().setOnClickListener(view -> {
                int current = holder.getBindingAdapterPosition();
                if (current != RecyclerView.NO_POSITION && current >= 0 && current < items.size()) {
                    listener.onClick(items.get(current));
                }
            });
            holder.binding.getRoot().setOnLongClickListener(view -> {
                int current = holder.getBindingAdapterPosition();
                if (current != RecyclerView.NO_POSITION && current >= 0 && current < items.size()) {
                    listener.onLongPress(items.get(current));
                }
                return true;
            });
        }

        private void bindPlate(Holder holder, JsonObject item) {
            long categories = Jsons.longValue(item, "category_count");
            long postsCount = Jsons.longValue(item, "post_count");
            showBadge(holder.binding.categoryBadge, categories + " 个二级分类");
            showBadge(holder.binding.stateBadge, postsCount + " 篇帖子");
            holder.binding.tags.setVisibility(View.GONE);
            holder.binding.metadata.setText(positionPrefix(item) + "点击进入板块并浏览二级分类");
        }

        private void bindPost(Holder holder, JsonObject item) {
            String category = Jsons.string(item, "category_name");
            if (category.isEmpty()) hideBadge(holder.binding.categoryBadge);
            else showBadge(holder.binding.categoryBadge, category);
            String state = postState(item);
            if (state.isEmpty()) hideBadge(holder.binding.stateBadge);
            else showBadge(holder.binding.stateBadge, state);
            JsonArray tags = Jsons.array(item, "tags");
            String tagText = joinTags(tags);
            holder.binding.tags.setVisibility(tagText.isEmpty() ? View.GONE : View.VISIBLE);
            holder.binding.tags.setText(tagText.isEmpty() ? "" : "#" + tagText);
            String author = fallback(Jsons.string(item, "nickname"), "用户");
            String metadata = positionPrefix(item) + author
                + " · " + Jsons.longValue(item, "unique_view_count") + " 阅读"
                + " · " + Jsons.longValue(item, "like_count") + " 赞"
                + " · " + Jsons.longValue(item, "comment_count") + " 评论";
            long sections = Jsons.longValue(item, "section_count");
            if (sections > 0) metadata += " · " + sections + " 节";
            holder.binding.metadata.setText(metadata);
        }

        private void bindAvatar(Holder holder, JsonObject item, String title) {
            String image = Jsons.string(item, posts ? "avatar" : "icon");
            if (!image.isEmpty()) {
                holder.binding.avatarImage.setVisibility(View.VISIBLE);
                holder.binding.avatarText.setVisibility(View.GONE);
                ImageLoader.get().loadThumbnail(
                    ImageLoader.get().absoluteUrl(holder.itemView.getContext(), image),
                    holder.binding.avatarImage,
                    posts ? R.drawable.ic_person : R.drawable.ic_forum);
            } else {
                holder.binding.avatarImage.setVisibility(View.GONE);
                holder.binding.avatarText.setVisibility(View.VISIBLE);
                String label = posts ? Jsons.string(item, "nickname") : title;
                holder.binding.avatarText.setText(label.isEmpty() ? "论" : label.substring(0, 1));
            }
        }

        private static String postState(JsonObject item) {
            if (flag(item, "is_top")) return "置顶";
            if (flag(item, "is_essence")) return "精华";
            if (flag(item, "is_locked")) return "已锁定";
            if (flag(item, "paid_content")) return "付费内容";
            String hot = Jsons.string(item, "hot_label");
            if (Jsons.longValue(item, "comment_count") <= 0 && (hot.contains("讨论") || hot.contains("热议"))) return "";
            return hot.isEmpty() ? "" : hot;
        }

        private static String positionPrefix(JsonObject item) {
            if ("top".equals(Jsons.string(item, "personal_position"))) return "个人置顶 · ";
            if ("bottom".equals(Jsons.string(item, "personal_position"))) return "个人置底 · ";
            return "";
        }

        private static String compact(String value) {
            return value == null ? "" : value.replace('\n', ' ').replace('\r', ' ').trim();
        }

        private static void showBadge(Chip chip, String text) {
            chip.setText(text);
            chip.setVisibility(View.VISIBLE);
        }

        private static void hideBadge(Chip chip) {
            chip.setText("");
            chip.setVisibility(View.GONE);
        }

        @Override public int getItemCount() { return items.size(); }

        static final class Holder extends RecyclerView.ViewHolder {
            final ItemForumBinding binding;
            Holder(ItemForumBinding binding) { super(binding.getRoot()); this.binding = binding; }
        }
    }
}
