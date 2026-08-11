package xyz.jjmxg.yiyunying.ui.resource;

import android.content.Context;
import android.content.Intent;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.text.Editable;
import android.text.TextUtils;
import android.text.TextWatcher;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.ScrollView;
import android.widget.TextView;

import androidx.annotation.NonNull;
import androidx.activity.result.ActivityResultLauncher;
import androidx.activity.result.contract.ActivityResultContracts;
import androidx.appcompat.app.AlertDialog;
import androidx.recyclerview.widget.LinearLayoutManager;
import androidx.recyclerview.widget.RecyclerView;

import com.google.android.material.button.MaterialButton;
import com.google.android.material.chip.Chip;
import com.google.android.material.snackbar.Snackbar;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;

import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Locale;
import java.util.Map;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.core.AppAccess;
import xyz.jjmxg.yiyunying.core.RuntimeLanguage;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.data.api.RequestHandle;
import xyz.jjmxg.yiyunying.databinding.ActivityResourceHallBinding;
import xyz.jjmxg.yiyunying.databinding.ItemForumBinding;
import xyz.jjmxg.yiyunying.ui.common.ImageLoader;
import xyz.jjmxg.yiyunying.ui.common.ActionIconResolver;
import xyz.jjmxg.yiyunying.ui.common.SystemInsetActivity;
import xyz.jjmxg.yiyunying.ui.common.YiyunyingDialogBuilder;
import xyz.jjmxg.yiyunying.ui.upload.FilePreviewActivity;

/** A typed, visual resource catalog. API records never render as raw JSON on this screen. */
public final class ResourceHallActivity extends SystemInsetActivity {
    private static final int MODE_APPS = 1;
    private static final int MODE_SOURCE = 2;
    private static final String EXTRA_MODE = "catalog_mode";
    private static final String EXTRA_TARGET_ID = "catalog_target_id";

    private final Handler handler = new Handler(Looper.getMainLooper());
    private final Runnable delayedSearch = this::loadItems;
    private ActivityResourceHallBinding binding;
    private ProductAdapter adapter;
    private RequestHandle listRequest;
    private RequestHandle categoryRequest;
    private RequestHandle detailRequest;
    private RequestHandle actionRequest;
    private RequestHandle policyRequest;
    private boolean resourceSubmissionEnabled;
    private boolean suppressModeChange;
    private boolean suppressSearchChange;
    private boolean rebuildingCategories;
    private boolean mineOnly;
    private boolean purchasedOnly;
    private int listGeneration;
    private int categoryGeneration;
    private String renderedCategorySnapshot = "";
    private int mode = MODE_APPS;
    private long selectedCategoryId;
    private final ActivityResultLauncher<Intent> submitResourceLauncher = registerForActivityResult(
        new ActivityResultContracts.StartActivityForResult(), result -> {
            if (result.getResultCode() == RESULT_OK && binding != null) reload();
        });

    public static void open(Context context) {
        context.startActivity(new Intent(context, ResourceHallActivity.class));
    }

    public static void openResource(Context context, long resourceId) {
        context.startActivity(targetIntent(context, MODE_SOURCE, resourceId));
    }

    public static void openApp(Context context, long storeAppId) {
        context.startActivity(targetIntent(context, MODE_APPS, storeAppId));
    }

    private static Intent targetIntent(Context context, int mode, long targetId) {
        return new Intent(context, ResourceHallActivity.class)
            .putExtra(EXTRA_MODE, mode)
            .putExtra(EXTRA_TARGET_ID, targetId);
    }

    @Override protected void onCreate(Bundle state) {
        super.onCreate(state);
        binding = ActivityResourceHallBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());
        binding.toolbar.setNavigationOnClickListener(view -> finish());
        binding.recycler.setLayoutManager(new LinearLayoutManager(this));
        binding.recycler.setHasFixedSize(true);
        binding.recycler.setItemViewCacheSize(8);
        binding.recycler.setItemAnimator(null);
        adapter = new ProductAdapter(this::loadDetail);
        binding.recycler.setAdapter(adapter);
        binding.swipeRefresh.setOnRefreshListener(this::reload);
        binding.modeGroup.addOnButtonCheckedListener((group, checkedId, isChecked) -> {
            if (!isChecked || suppressModeChange) return;
            int nextMode = checkedId == R.id.sourceButton ? MODE_SOURCE : MODE_APPS;
            if (mode == nextMode) return;
            mode = nextMode;
            selectedCategoryId = 0L;
            renderedCategorySnapshot = "";
            adapter.submit(new ArrayList<>());
            suppressSearchChange = true;
            try {
                binding.searchInput.setText("");
            } finally {
                suppressSearchChange = false;
            }
            reload();
        });
        binding.scopeGroup.addOnButtonCheckedListener((group, checkedId, isChecked) -> {
            if (!isChecked) return;
            boolean nextMineOnly = checkedId == R.id.mineScopeButton;
            boolean nextPurchasedOnly = checkedId == R.id.purchasedScopeButton;
            if (mineOnly == nextMineOnly && purchasedOnly == nextPurchasedOnly) return;
            mineOnly = nextMineOnly;
            purchasedOnly = nextPurchasedOnly;
            adapter.submit(new ArrayList<>());
            loadItems();
        });
        binding.searchInput.addTextChangedListener(new TextWatcher() {
            @Override public void beforeTextChanged(CharSequence value, int start, int count, int after) { }
            @Override public void onTextChanged(CharSequence value, int start, int before, int count) {
                if (suppressSearchChange) return;
                handler.removeCallbacks(delayedSearch);
                handler.postDelayed(delayedSearch, 320L);
            }
            @Override public void afterTextChanged(Editable value) { }
        });
        binding.searchLayout.setEndIconOnClickListener(view -> loadItems());
        binding.submitResourceButton.setOnClickListener(view -> {
            if (!resourceSubmissionEnabled) {
                Snackbar.make(binding.getRoot(), "当前应用暂未开放用户资源投稿", Snackbar.LENGTH_LONG).show();
                return;
            }
            submitResourceLauncher.launch(ResourceSubmitActivity.intent(this,
                mode == MODE_APPS ? ResourceSubmitActivity.MODE_APPS : ResourceSubmitActivity.MODE_SOURCE));
        });
        int requestedMode = getIntent().getIntExtra(EXTRA_MODE, MODE_APPS);
        mode = requestedMode == MODE_SOURCE ? MODE_SOURCE : MODE_APPS;
        suppressModeChange = true;
        try {
            if (mode == MODE_SOURCE) binding.sourceButton.setChecked(true);
            else binding.appsButton.setChecked(true);
        } finally {
            suppressModeChange = false;
        }
        reload();
        long targetId = getIntent().getLongExtra(EXTRA_TARGET_ID, 0L);
        if (targetId > 0) {
            binding.getRoot().post(() -> loadDetail(targetId, mode));
            getIntent().removeExtra(EXTRA_TARGET_ID);
        }
    }

    private void reload() {
        binding.submitResourceButton.setVisibility(View.VISIBLE);
        loadSubmissionPolicy();
        loadItems();
        if (mode == MODE_SOURCE) {
            loadSourceCategories();
        } else loadAppCategories();
    }

    private void loadSubmissionPolicy() {
        if (policyRequest != null) policyRequest.cancel();
        resourceSubmissionEnabled = false;
        binding.submitResourceButton.setEnabled(false);
        binding.submitResourceButton.setText("正在读取投稿权限…");
        final int requestedMode = mode;
        String path = requestedMode == MODE_APPS
            ? "/api/user/store-submission-policy"
            : "/api/user/resource-submission-policy";
        policyRequest = AppAccess.from(this).repository().get(
            path, new LinkedHashMap<>(), result -> {
                policyRequest = null;
                if (binding == null || mode != requestedMode) return;
                if (!result.isSuccessful()) {
                    binding.submitResourceButton.setText("投稿权限读取失败");
                    return;
                }
                resourceSubmissionEnabled = booleanValue(result.dataObject(), "enabled");
                binding.submitResourceButton.setEnabled(resourceSubmissionEnabled);
                binding.submitResourceButton.setText(resourceSubmissionEnabled
                    ? (requestedMode == MODE_APPS ? "投稿应用" : "投稿源码")
                    : "投稿暂未开放");
            });
    }

    private void loadAppCategories() {
        loadCategories("/api/user/store-categories");
    }

    private void loadSourceCategories() {
        loadCategories("/api/user/resource-categories");
    }

    private void loadCategories(String path) {
        if (categoryRequest != null) categoryRequest.cancel();
        final int requestedMode = mode;
        final int generation = ++categoryGeneration;
        LinkedHashMap<String, String> query = new LinkedHashMap<>();
        if (requestedMode == MODE_SOURCE) query.put("resource_type", "source_market");
        AppAccess.from(this).repository().getCached(path, query, cached -> {
            if (!isCurrentCategoryRequest(requestedMode, generation) || !cached.isSuccessful()) return;
            renderCategories(cached.objectItems(), requestedMode);
        });
        categoryRequest = AppAccess.from(this).repository().get(
            path, query, result -> {
                categoryRequest = null;
                if (!isCurrentCategoryRequest(requestedMode, generation) || !result.isSuccessful()) return;
                renderCategories(result.objectItems(), requestedMode);
            }
        );
    }

    private boolean isCurrentCategoryRequest(int requestedMode, int generation) {
        return binding != null && !isFinishing() && !isDestroyed()
            && mode == requestedMode && categoryGeneration == generation;
    }

    private void renderCategories(List<JsonObject> categories, int requestedMode) {
        boolean selectedStillExists = selectedCategoryId <= 0;
        StringBuilder snapshot = new StringBuilder().append(requestedMode);
        for (JsonObject category : categories) {
            long id = Jsons.longValue(category, "id");
            String name = Jsons.string(category, "name").trim();
            if (id <= 0 || name.isEmpty()) continue;
            if (id == selectedCategoryId) selectedStillExists = true;
            snapshot.append('|').append(id).append(':').append(name);
        }
        if (!selectedStillExists) {
            selectedCategoryId = 0L;
            loadItems();
        }
        String nextSnapshot = snapshot.toString();
        if (nextSnapshot.equals(renderedCategorySnapshot)) return;
        renderedCategorySnapshot = nextSnapshot;
        rebuildingCategories = true;
        try {
            binding.categoryChips.removeAllViews();
            addCategoryChip("全部", 0L);
            for (JsonObject category : categories) {
                long id = Jsons.longValue(category, "id");
                String name = Jsons.string(category, "name").trim();
                if (id <= 0 || name.isEmpty()) continue;
                addCategoryChip(name, id);
            }
        } finally {
            rebuildingCategories = false;
        }
    }

    private void addCategoryChip(String name, long id) {
        Chip chip = categoryChip(name);
        chip.setId(View.generateViewId());
        chip.setTag(id);
        chip.setChecked(id == selectedCategoryId);
        chip.setOnCheckedChangeListener((button, checked) -> {
            if (!checked || rebuildingCategories || selectedCategoryId == id) return;
            selectedCategoryId = id;
            loadItems();
        });
        binding.categoryChips.addView(chip);
    }

    private Chip categoryChip(String label) {
        Chip chip = new Chip(this);
        chip.setText(label);
        chip.setCheckable(true);
        chip.setEnsureMinTouchTargetSize(true);
        return chip;
    }

    private void loadItems() {
        handler.removeCallbacks(delayedSearch);
        if (binding == null) return;
        if (listRequest != null) listRequest.cancel();
        final int requestedMode = mode;
        final long requestedCategoryId = selectedCategoryId;
        final String requestedKeyword = text(binding.searchInput.getText());
        final boolean requestedMineOnly = mineOnly;
        final boolean requestedPurchasedOnly = purchasedOnly;
        final int generation = ++listGeneration;
        binding.progress.setVisibility(View.VISIBLE);
        Map<String, String> query = new LinkedHashMap<>();
        query.put("limit", "100");
        if (requestedMode == MODE_SOURCE) query.put("resource_type", "source_market");
        if (requestedMineOnly) query.put("mine", "1");
        if (requestedPurchasedOnly) query.put("purchased", "1");
        if (!requestedKeyword.isEmpty()) query.put("keyword", requestedKeyword);
        if (requestedCategoryId > 0) {
            query.put("category_id", String.valueOf(requestedCategoryId));
        }
        String path = requestedMode == MODE_APPS ? "/api/user/store-apps" : "/api/user/resources";
        AppAccess.from(this).repository().getCached(path, query, cached -> {
            if (!isCurrentListRequest(requestedMode, requestedCategoryId, requestedKeyword,
                requestedMineOnly, requestedPurchasedOnly, generation)
                || !cached.isSuccessful()) return;
            List<JsonObject> cachedItems = cached.objectItems();
            renderItems(cachedItems);
            binding.progress.setVisibility(View.INVISIBLE);
        });
        listRequest = AppAccess.from(this).repository().get(path, query, result -> {
            listRequest = null;
            if (!isCurrentListRequest(requestedMode, requestedCategoryId, requestedKeyword,
                requestedMineOnly, requestedPurchasedOnly, generation)) return;
            binding.progress.setVisibility(View.INVISIBLE);
            binding.swipeRefresh.setRefreshing(false);
            if (!result.isSuccessful()) {
                if (adapter.getItemCount() == 0) adapter.submit(new ArrayList<>());
                binding.emptyText.setText(result.message().isEmpty() ? "内容加载失败，请稍后重试" : result.message());
                binding.emptyText.setVisibility(adapter.getItemCount() == 0 ? View.VISIBLE : View.GONE);
                return;
            }
            List<JsonObject> items = result.objectItems();
            renderItems(items);
        });
    }

    private boolean isCurrentListRequest(
        int requestedMode,
        long requestedCategoryId,
        String requestedKeyword,
        boolean requestedMineOnly,
        boolean requestedPurchasedOnly,
        int generation
    ) {
        return binding != null && !isFinishing() && !isDestroyed()
            && mode == requestedMode
            && selectedCategoryId == requestedCategoryId
            && requestedKeyword.equals(text(binding.searchInput.getText()))
            && mineOnly == requestedMineOnly
            && purchasedOnly == requestedPurchasedOnly
            && listGeneration == generation;
    }

    private void renderItems(List<JsonObject> items) {
        adapter.submit(items);
        binding.emptyText.setText(mineOnly
            ? (mode == MODE_APPS ? "你还没有投稿应用" : "你还没有投稿源码")
            : purchasedOnly
                ? (mode == MODE_APPS ? "你还没有购买应用" : "你还没有购买源码资源")
                : (mode == MODE_APPS ? "暂无可用应用" : "暂无可用源码资源"));
        binding.emptyText.setVisibility(items.isEmpty() ? View.VISIBLE : View.GONE);
    }

    private void loadDetail(JsonObject summary) {
        loadDetail(Jsons.longValue(summary, "id"), mode);
    }

    private void loadDetail(long id, int requestedMode) {
        if (detailRequest != null) detailRequest.cancel();
        if (id <= 0) return;
        binding.progress.setVisibility(View.VISIBLE);
        String path = requestedMode == MODE_APPS ? "/api/user/store-apps/" + id : "/api/user/resources/" + id;
        LinkedHashMap<String, String> query = new LinkedHashMap<>();
        AppAccess.from(this).repository().getCached(path, query, cached -> {
            if (binding == null || isFinishing() || isDestroyed() || !cached.isSuccessful()) return;
            JsonObject data = cached.dataObject();
            String key = requestedMode == MODE_APPS ? "store_app" : "resource";
            JsonObject detail = data.has(key) && data.get(key).isJsonObject() ? data.getAsJsonObject(key) : data;
            if (detail.size() > 0) showDetail(detail, requestedMode);
            binding.progress.setVisibility(View.INVISIBLE);
        });
        detailRequest = AppAccess.from(this).repository().get(path, query, result -> {
            detailRequest = null;
            if (binding == null) return;
            binding.progress.setVisibility(View.INVISIBLE);
            if (!result.isSuccessful()) {
                Snackbar.make(binding.getRoot(), result.message().isEmpty() ? "详情加载失败" : result.message(), Snackbar.LENGTH_LONG).show();
                return;
            }
            JsonObject data = result.dataObject();
            String key = requestedMode == MODE_APPS ? "store_app" : "resource";
            JsonObject detail = data.has(key) && data.get(key).isJsonObject() ? data.getAsJsonObject(key) : data;
            showDetail(detail, requestedMode);
        });
    }

    private void showDetail(JsonObject item, int itemMode) {
        String title = itemMode == MODE_APPS ? Jsons.string(item, "name") : Jsons.string(item, "title");
        boolean interactive = booleanValue(item, "interaction_enabled");
        ScrollView scroll = new ScrollView(this);
        LinearLayout content = new LinearLayout(this);
        content.setOrientation(LinearLayout.VERTICAL);
        int padding = dp(18);
        content.setPadding(padding, dp(4), padding, dp(8));
        scroll.addView(content);

        String imageUrl = itemMode == MODE_APPS ? Jsons.string(item, "icon_url") : Jsons.string(item, "cover_url");
        imageUrl = ImageLoader.get().absoluteUrl(this, imageUrl);
        if (!imageUrl.isEmpty()) {
            ImageView image = new ImageView(this);
            image.setScaleType(ImageView.ScaleType.CENTER_CROP);
            content.addView(image, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, dp(180)));
            ImageLoader.get().load(imageUrl, image, itemMode == MODE_APPS ? R.drawable.ic_apps : R.drawable.ic_content);
        }
        addField(content, "分类", valueOr(Jsons.string(item, "category_name"), "未分类"));
        if (itemMode == MODE_APPS) {
            addField(content, "版本", versionText(item));
            addField(content, "包名", valueOr(Jsons.string(item, "package_name"), "未提供"));
            addField(content, "安装包大小", sizeText(Jsons.longValue(item, "size_bytes")));
            int price = Jsons.intValue(item, "price_balance", 0);
            addField(content, "获取方式", price > 0 ? price + " 余额" : "免费");
        } else {
            int price = Jsons.intValue(item, "price_balance", 0);
            addField(content, "价格", price > 0 ? price + " 余额" : "免费");
            addField(content, "发布者", valueOr(Jsons.string(item, "nickname"), "平台用户"));
        }
        String auditStatus = Jsons.string(item, "audit_status");
        if (!"approved".equals(auditStatus) || booleanValue(item, "is_owner")) {
            addField(content, "审核状态", valueOr(Jsons.string(item, "audit_status_label"), auditStatus));
            String auditReason = Jsons.string(item, "audit_reason");
            if (!auditReason.isEmpty()) addSection(content, "审核说明", auditReason);
        }
        addField(content, "下载次数", String.valueOf(Jsons.intValue(item, "download_count", 0)));
        addSection(content, "详细介绍", valueOr(Jsons.string(item, "description"), "发布者暂未填写详细介绍。"));

        if (interactive) {
            MaterialButton favoriteButton = new MaterialButton(this, null,
                com.google.android.material.R.attr.materialButtonOutlinedStyle);
            favoriteButton.setIconResource(R.drawable.ic_favorite);
            updateFavoriteButton(favoriteButton, booleanValue(item, "favorited"));
            LinearLayout.LayoutParams favoriteParams = new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT);
            favoriteParams.topMargin = dp(14);
            content.addView(favoriteButton, favoriteParams);
            favoriteButton.setOnClickListener(view -> toggleFavorite(item, itemMode, favoriteButton));
        } else {
            addSection(content, "当前不可互动",
                Jsons.string(item, itemMode == MODE_APPS ? "apk_url" : "download_url").isEmpty()
                    ? "当前条目已停止公开，不能收藏、购买或评论。"
                    : "当前条目已停止公开，不能收藏、购买或评论；你已获得的下载权限仍然保留。");
        }

        String downloadField = itemMode == MODE_APPS ? "apk_url" : "download_url";
        String download = ImageLoader.get().absoluteUrl(this, Jsons.string(item, downloadField));
        int price = itemMode == MODE_APPS
            ? Jsons.intValue(item, "price_balance", 0)
            : Jsons.intValue(item, "price_balance", 0);
        boolean canDownload = !download.isEmpty();
        String action = canDownload ? (itemMode == MODE_APPS ? "下载安装包" : "查看文件")
            : (!interactive ? "知道了"
            : (price > 0 ? "余额购买" : "暂不可获取"));
        AlertDialog dialog = new YiyunyingDialogBuilder(this)
            .setBusinessTitle(valueOr(title, itemMode == MODE_APPS ? "应用详情" : "源码详情"))
            .setView(scroll)
            .setNegativeButton("关闭", null)
            .setPositiveButton(action, null)
            .create();
        dialog.setOnShowListener(ignored -> dialog.getButton(AlertDialog.BUTTON_POSITIVE).setOnClickListener(view -> {
            if (canDownload) {
                dialog.dismiss();
                openDownload(item, itemMode, download);
            } else if (!interactive) {
                dialog.dismiss();
            } else if (price > 0 && itemMode == MODE_SOURCE) {
                dialog.dismiss();
                confirmPurchase(item);
            } else if (price > 0 && itemMode == MODE_APPS) {
                dialog.dismiss();
                confirmStorePurchase(item);
            } else {
                dialog.dismiss();
                Snackbar.make(binding.getRoot(),
                    "文件尚未完成安全迁移，请联系发布者重新上传",
                    Snackbar.LENGTH_LONG).show();
            }
        }));
        dialog.show();
    }

    private void toggleFavorite(JsonObject item, int itemMode, MaterialButton button) {
        if (actionRequest != null) return;
        long id = Jsons.longValue(item, "id");
        if (id <= 0) return;
        button.setEnabled(false);
        JsonObject body = new JsonObject();
        body.addProperty("reaction_type", "favorite");
        String path = itemMode == MODE_APPS
            ? "/api/user/store-apps/" + id + "/reactions"
            : "/api/user/resources/" + id + "/reactions";
        actionRequest = AppAccess.from(this).repository().post(path, body, result -> {
            actionRequest = null;
            if (binding == null) return;
            button.setEnabled(true);
            if (!result.isSuccessful()) {
                Snackbar.make(binding.getRoot(),
                    result.message().isEmpty() ? "收藏操作失败，请稍后重试" : result.message(),
                    Snackbar.LENGTH_LONG).show();
                return;
            }
            boolean active = booleanValue(result.dataObject(), "active");
            item.addProperty("favorited", active);
            updateFavoriteButton(button, active);
            Snackbar.make(binding.getRoot(), active ? "已加入我的收藏" : "已取消收藏", Snackbar.LENGTH_SHORT).show();
        });
    }

    private void updateFavoriteButton(MaterialButton button, boolean active) {
        button.setText(active ? "已收藏" : "收藏");
        ActionIconResolver.apply(button, active ? "取消收藏" : "加入收藏", 0);
    }

    private void confirmPurchase(JsonObject item) {
        long id = Jsons.longValue(item, "id");
        int price = Jsons.intValue(item, "price_balance", 0);
        long sourceUploadId = Jsons.longValue(item, "source_upload_id");
        String title = valueOr(Jsons.string(item, "title"), "该资源");
        YiyunyingDialogBuilder builder = new YiyunyingDialogBuilder(this);
        builder.setTitle("确认获取资源");
        builder.setBusinessMessage(price > 0 ? "将使用 " + price + " 余额购买“" + title + "”。购买成功后可查看文件。" : "确认免费获取“" + title + "”？")
            .setNegativeButton("取消", null)
            .setPositiveButton(price > 0 ? "确认购买" : "免费获取",
                (dialog, which) -> buyResource(id, title, price, sourceUploadId))
            .show();
    }

    private void buyResource(long id, String title, int expectedPrice, long expectedSourceUploadId) {
        if (actionRequest != null) return;
        JsonObject body = new JsonObject();
        body.addProperty("expected_price_balance", expectedPrice);
        body.addProperty("expected_source_upload_id", expectedSourceUploadId);
        actionRequest = AppAccess.from(this).repository().post("/api/user/resources/" + id + "/buy", body, result -> {
            actionRequest = null;
            if (binding == null) return;
            if (!result.isSuccessful()) {
                Snackbar.make(binding.getRoot(), result.message().isEmpty() ? "购买失败，请检查余额后重试" : result.message(), Snackbar.LENGTH_LONG).show();
                return;
            }
            Snackbar.make(binding.getRoot(), "已获取资源，可立即查看文件", Snackbar.LENGTH_LONG).show();
            String url = ImageLoader.get().absoluteUrl(this, Jsons.string(result.dataObject(), "download_url"));
            if (!url.isEmpty()) FilePreviewActivity.open(this, title, url, mimeFor(title, url));
            loadItems();
        });
    }

    private void confirmStorePurchase(JsonObject item) {
        long id = Jsons.longValue(item, "id");
        int price = Jsons.intValue(item, "price_balance", 0);
        long sourceUploadId = Jsons.longValue(item, "source_upload_id");
        int versionCode = Jsons.intValue(item, "version_code", 0);
        String title = valueOr(Jsons.string(item, "name"), "应用安装包");
        new YiyunyingDialogBuilder(this)
            .setBusinessTitle("确认购买应用")
            .setBusinessMessage("将支付 " + price + " 余额购买《" + title
                + "》。购买成功后可重复下载当前版本。")
            .setPositiveButton("确认购买",
                (dialog, which) -> buyStoreApp(id, title, price, sourceUploadId, versionCode))
            .setNegativeButton("取消", null)
            .show();
    }

    private void buyStoreApp(
        long id,
        String title,
        int expectedPrice,
        long expectedSourceUploadId,
        int expectedVersionCode
    ) {
        if (actionRequest != null) return;
        JsonObject body = new JsonObject();
        body.addProperty("expected_price_balance", expectedPrice);
        body.addProperty("expected_source_upload_id", expectedSourceUploadId);
        body.addProperty("expected_version_code", expectedVersionCode);
        actionRequest = AppAccess.from(this).repository().post(
            "/api/user/store-apps/" + id + "/buy", body, result -> {
                actionRequest = null;
                if (binding == null) return;
                if (!result.isSuccessful()) {
                    Snackbar.make(binding.getRoot(), result.message().isEmpty()
                        ? "应用购买失败，请稍后重试" : result.message(),
                        Snackbar.LENGTH_LONG).show();
                    return;
                }
                String url = ImageLoader.get().absoluteUrl(this,
                    Jsons.string(result.dataObject(), "apk_url"));
                if (url.isEmpty()) {
                    Snackbar.make(binding.getRoot(),
                        "购买成功，但安装包暂不可下载", Snackbar.LENGTH_LONG).show();
                    return;
                }
                FilePreviewActivity.open(this, apkFileName(title), url,
                    "application/vnd.android.package-archive");
                loadItems();
            });
    }

    private void openDownload(JsonObject item, int itemMode, String url) {
        String title = itemMode == MODE_APPS ? Jsons.string(item, "name") : Jsons.string(item, "title");
        String fileName = itemMode == MODE_APPS ? apkFileName(title) : valueOr(title, "下载文件");
        String mime = itemMode == MODE_APPS
            ? "application/vnd.android.package-archive" : mimeFor(title, url);
        FilePreviewActivity.open(this, fileName, url, mime);
    }

    private static String apkFileName(String title) {
        String value = valueOr(title, "应用安装包");
        return value.toLowerCase(Locale.ROOT).endsWith(".apk") ? value : value + ".apk";
    }

    private void addField(LinearLayout parent, String label, String value) {
        TextView view = new TextView(this);
        view.setText(label + "\n" + value);
        view.setTextAppearance(com.google.android.material.R.style.TextAppearance_Material3_BodyLarge);
        view.setTextColor(getColor(R.color.on_surface));
        view.setPadding(0, dp(12), 0, dp(4));
        parent.addView(view, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));
        RuntimeLanguage.protectDynamicText(view);
    }

    private void addSection(LinearLayout parent, String label, String value) {
        TextView heading = new TextView(this);
        heading.setText(label);
        heading.setTextAppearance(com.google.android.material.R.style.TextAppearance_Material3_TitleMedium);
        heading.setTextColor(getColor(R.color.on_surface));
        heading.setPadding(0, dp(16), 0, dp(5));
        parent.addView(heading);
        TextView body = new TextView(this);
        body.setText(value);
        body.setTextAppearance(com.google.android.material.R.style.TextAppearance_Material3_BodyMedium);
        body.setTextColor(getColor(R.color.on_surface_variant));
        body.setLineSpacing(0f, 1.15f);
        parent.addView(body);
        RuntimeLanguage.protectDynamicText(body);
    }

    private static String versionText(JsonObject item) {
        String name = Jsons.string(item, "version_name");
        int code = Jsons.intValue(item, "version_code", 0);
        if (name.isEmpty() && code <= 0) return "未提供";
        return code > 0 ? valueOr(name, "版本") + "（" + code + "）" : name;
    }

    private static String sizeText(long bytes) {
        if (bytes <= 0) return "未提供";
        if (bytes >= 1024L * 1024L * 1024L) return String.format(Locale.CHINA, "%.2f GB", bytes / 1073741824d);
        if (bytes >= 1024L * 1024L) return String.format(Locale.CHINA, "%.1f MB", bytes / 1048576d);
        if (bytes >= 1024L) return String.format(Locale.CHINA, "%.1f KB", bytes / 1024d);
        return bytes + " B";
    }

    private static String mimeFor(String name, String url) {
        String value = (name + " " + url).toLowerCase(Locale.ROOT);
        if (value.contains(".apk")) return "application/vnd.android.package-archive";
        if (value.contains(".zip")) return "application/zip";
        if (value.contains(".7z")) return "application/x-7z-compressed";
        if (value.contains(".pdf")) return "application/pdf";
        if (value.contains(".mp4")) return "video/mp4";
        if (value.contains(".mp3")) return "audio/mpeg";
        if (value.contains(".png")) return "image/png";
        if (value.contains(".jpg") || value.contains(".jpeg")) return "image/jpeg";
        return "application/octet-stream";
    }

    private static boolean booleanValue(JsonObject object, String key) {
        if (object == null || !object.has(key) || object.get(key).isJsonNull()) return false;
        try { return object.get(key).getAsBoolean(); } catch (RuntimeException ignored) { return false; }
    }

    private static String text(CharSequence value) { return value == null ? "" : value.toString().trim(); }
    private static String valueOr(String value, String fallback) { return TextUtils.isEmpty(value) ? fallback : value; }
    private int dp(int value) { return Math.round(value * getResources().getDisplayMetrics().density); }

    @Override protected void onDestroy() {
        handler.removeCallbacksAndMessages(null);
        if (listRequest != null) listRequest.cancel();
        if (categoryRequest != null) categoryRequest.cancel();
        if (detailRequest != null) detailRequest.cancel();
        if (actionRequest != null) actionRequest.cancel();
        if (policyRequest != null) policyRequest.cancel();
        binding = null;
        super.onDestroy();
    }

    private final class ProductAdapter extends RecyclerView.Adapter<ProductAdapter.Holder> {
        interface Listener { void onClick(JsonObject item); }
        private final List<JsonObject> items = new ArrayList<>();
        private final Listener listener;

        ProductAdapter(Listener listener) { this.listener = listener; setHasStableIds(true); }

        void submit(List<JsonObject> next) {
            List<JsonObject> safe = next == null ? new ArrayList<>() : next;
            if (hasSameIdentityOrder(items, safe)) {
                List<Integer> changed = new ArrayList<>();
                for (int index = 0; index < safe.size(); index++) {
                    if (!items.get(index).equals(safe.get(index))) changed.add(index);
                }
                if (changed.isEmpty()) return;
                items.clear();
                items.addAll(safe);
                if (changed.size() > 16) {
                    notifyItemRangeChanged(0, items.size());
                } else {
                    for (int position : changed) notifyItemChanged(position);
                }
                return;
            }
            items.clear();
            items.addAll(safe);
            notifyDataSetChanged();
        }

        private boolean hasSameIdentityOrder(List<JsonObject> before, List<JsonObject> after) {
            if (before.size() != after.size()) return false;
            for (int index = 0; index < before.size(); index++) {
                if (Jsons.longValue(before.get(index), "id")
                    != Jsons.longValue(after.get(index), "id")) return false;
            }
            return true;
        }

        @Override public long getItemId(int position) { return Jsons.longValue(items.get(position), "id"); }

        @NonNull @Override public Holder onCreateViewHolder(@NonNull ViewGroup parent, int viewType) {
            return new Holder(ItemForumBinding.inflate(LayoutInflater.from(parent.getContext()), parent, false));
        }

        @Override public void onBindViewHolder(@NonNull Holder holder, int position) {
            JsonObject item = items.get(position);
            boolean app = mode == MODE_APPS;
            String title = app ? Jsons.string(item, "name") : Jsons.string(item, "title");
            RuntimeLanguage.setDynamicText(holder.binding.title, valueOr(title, app ? "未命名应用" : "未命名源码"));
            String category = Jsons.string(item, "category_name");
            holder.binding.categoryBadge.setText(valueOr(category, "未分类"));
            holder.binding.categoryBadge.setVisibility(View.VISIBLE);
            int price = Jsons.intValue(item, "price_balance", 0);
            String auditStatus = Jsons.string(item, "audit_status");
            String auditLabel = Jsons.string(item, "audit_status_label");
            boolean currentlyPublic = "approved".equals(auditStatus) && Jsons.intValue(item, "status", 0) == 1;
            holder.binding.stateBadge.setText(purchasedOnly && !currentlyPublic
                ? "历史已购 · 当前已下架"
                : mineOnly || !"approved".equals(auditStatus)
                    ? valueOr(auditLabel, auditStatus)
                    : (price > 0 ? price + " 余额" : "免费"));
            holder.binding.stateBadge.setVisibility(View.VISIBLE);
            holder.binding.subtitle.setText(valueOr(Jsons.string(item, "description"), "暂无简介，点击查看详情"));
            RuntimeLanguage.protectDynamicText(holder.binding.subtitle);
            String metadata = app
                ? "版本 " + versionText(item) + "  ·  下载 " + Jsons.intValue(item, "download_count", 0)
                : "浏览 " + Jsons.intValue(item, "view_count", 0) + "  ·  下载 " + Jsons.intValue(item, "download_count", 0);
            String auditReason = Jsons.string(item, "audit_reason");
            if ((mineOnly || purchasedOnly) && !auditReason.isEmpty()) metadata += "  ·  " + auditReason;
            holder.binding.metadata.setText(metadata);
            holder.binding.tags.setVisibility(View.GONE);
            String image = app ? Jsons.string(item, "icon_url") : Jsons.string(item, "cover_url");
            image = ImageLoader.get().absoluteUrl(ResourceHallActivity.this, image);
            holder.binding.avatarImage.setVisibility(image.isEmpty() ? View.GONE : View.VISIBLE);
            holder.binding.avatarText.setVisibility(image.isEmpty() ? View.VISIBLE : View.GONE);
            holder.binding.avatarText.setText(app ? "应" : "源");
            if (!image.isEmpty()) ImageLoader.get().loadThumbnail(
                image,
                holder.binding.avatarImage,
                app ? R.drawable.ic_apps : R.drawable.ic_content
            );
            holder.binding.getRoot().setOnClickListener(view -> {
                int current = holder.getBindingAdapterPosition();
                if (current != RecyclerView.NO_POSITION) listener.onClick(items.get(current));
            });
        }

        @Override public int getItemCount() { return items.size(); }

        final class Holder extends RecyclerView.ViewHolder {
            final ItemForumBinding binding;
            Holder(ItemForumBinding binding) { super(binding.getRoot()); this.binding = binding; }
        }
    }
}
