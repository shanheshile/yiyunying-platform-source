package xyz.jjmxg.yiyunying.ui.home;

import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.widget.LinearLayout;
import android.widget.TextView;

import androidx.annotation.NonNull;
import androidx.annotation.Nullable;
import androidx.recyclerview.widget.LinearLayoutManager;
import androidx.recyclerview.widget.PagerSnapHelper;

import com.google.android.material.chip.Chip;
import xyz.jjmxg.yiyunying.ui.common.YiyunyingDialogBuilder;
import com.google.gson.JsonArray;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;

import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

import okhttp3.HttpUrl;
import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.core.RuntimeLanguage;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.databinding.FragmentUserHomeBinding;
import xyz.jjmxg.yiyunying.ui.common.BaseFragment;
import xyz.jjmxg.yiyunying.ui.common.ImageLoader;
import xyz.jjmxg.yiyunying.ui.common.UiGuard;
import xyz.jjmxg.yiyunying.ui.upload.UploadPolicyStore;

public final class UserHomeFragment extends BaseFragment {
    private FragmentUserHomeBinding binding;
    private final BannerAdapter banners = new BannerAdapter();
    private final Handler carouselHandler = new Handler(Looper.getMainLooper());
    private int carouselPosition;
    private boolean popupShown;
    private NoticeDisplayStore noticeDisplayStore;
    private long loadGeneration;
    private long noticeRequestGeneration = -1L;
    private long userNoticesLoadedGeneration = -1L;
    private boolean hasBootstrapContent;
    private String appSnapshot = "";
    private String bannerSnapshot = "";
    private String noticeSnapshot = "";
    private String featureSnapshot = "";
    private JsonArray bootstrapNotices = new JsonArray();

    private final Runnable carousel = new Runnable() {
        @Override public void run() {
            if (binding == null || banners.getItemCount() < 2) return;
            carouselPosition = (carouselPosition + 1) % banners.getItemCount();
            binding.bannerList.smoothScrollToPosition(carouselPosition);
            carouselHandler.postDelayed(this, 4500L);
        }
    };

    public static UserHomeFragment newInstance() { return new UserHomeFragment(); }

    @Nullable
    @Override public View onCreateView(@NonNull LayoutInflater inflater, @Nullable ViewGroup container, @Nullable Bundle state) {
        binding = FragmentUserHomeBinding.inflate(inflater, container, false);
        noticeDisplayStore = new NoticeDisplayStore(requireContext());
        host().setPageTitle("首页");
        binding.bannerList.setLayoutManager(new LinearLayoutManager(requireContext(), LinearLayoutManager.HORIZONTAL, false));
        binding.bannerList.setAdapter(banners);
        binding.bannerList.setHasFixedSize(true);
        binding.bannerList.setItemViewCacheSize(4);
        binding.bannerList.setItemAnimator(null);
        new PagerSnapHelper().attachToRecyclerView(binding.bannerList);
        binding.swipeRefresh.setOnRefreshListener(this::load);
        binding.retryButton.setOnClickListener(view -> load());
        load();
        return binding.getRoot();
    }

    private void load() {
        long generation = ++loadGeneration;
        binding.progress.setVisibility(hasBootstrapContent ? View.GONE : View.VISIBLE);
        track(app().repository().getCached("/api/public/bootstrap", new LinkedHashMap<>(), result -> {
            if (binding == null || generation != loadGeneration || !result.isSuccessful()) return;
            applyBootstrap(result.dataObject(), generation, true);
        }));
        track(app().repository().getPublic("/api/public/bootstrap", new LinkedHashMap<>(), result -> {
            if (binding == null || generation != loadGeneration) return;
            binding.progress.setVisibility(View.GONE);
            binding.swipeRefresh.setRefreshing(false);
            if (!result.isSuccessful()) {
                if (hasBootstrapContent) return;
                binding.errorMessage.setText(result.message().isEmpty() ? "首页加载失败" : result.message());
                binding.errorState.setVisibility(View.VISIBLE);
                return;
            }
            binding.errorState.setVisibility(View.GONE);
            UploadPolicyStore.update(requireContext(), Jsons.object(result.dataObject(), "upload_limits"));
            UiGuard.run(binding.getRoot(), "用户首页渲染", () -> render(result.dataObject()));
        }));
    }

    private void applyBootstrap(JsonObject data, long generation, boolean cached) {
        if (binding == null || generation != loadGeneration) return;
        hasBootstrapContent = true;
        binding.progress.setVisibility(View.GONE);
        binding.errorState.setVisibility(View.GONE);
        UploadPolicyStore.update(requireContext(), Jsons.object(data, "upload_limits"));
        UiGuard.run(binding.getRoot(), cached ? "home/cache" : "home/network", () -> render(data));
    }

    private void render(JsonObject data) {
        long generation = loadGeneration;
        hasBootstrapContent = true;
        binding.errorState.setVisibility(View.GONE);
        JsonObject appData = Jsons.object(data, "app");
        String nextAppSnapshot = appData.toString();
        if (!nextAppSnapshot.equals(appSnapshot)) {
            appSnapshot = nextAppSnapshot;
        RuntimeLanguage.setDynamicText(binding.appName, Jsons.string(appData, "name"));
        RuntimeLanguage.setDynamicText(binding.appDescription, Jsons.string(appData, "description"));
        binding.appVersion.setText("版本 " + Jsons.string(appData, "version"));
        ImageLoader.get().load(resolveUrl(Jsons.string(appData, "logo")), binding.appLogo, R.drawable.ic_logo_foreground);
        }

        JsonArray rawBanners = Jsons.array(data, "banners");
        String nextBannerSnapshot = rawBanners.toString();
        List<JsonObject> bannerItems = objects(rawBanners);
        for (JsonObject banner : bannerItems) {
            String image = Jsons.string(banner, "image_url");
            if (!image.isEmpty()) banner.addProperty("image_url", resolveUrl(image));
            String link = Jsons.string(banner, "link_url");
            if (!link.isEmpty()) banner.addProperty("link_url", resolveUrl(link));
        }
        if (!nextBannerSnapshot.equals(bannerSnapshot)) {
            bannerSnapshot = nextBannerSnapshot;
            banners.submit(bannerItems);
            binding.bannerList.setVisibility(bannerItems.isEmpty() ? View.GONE : View.VISIBLE);
            carouselPosition = Math.min(carouselPosition, Math.max(0, bannerItems.size() - 1));
            carouselHandler.removeCallbacks(carousel);
            if (bannerItems.size() > 1) carouselHandler.postDelayed(carousel, 4500L);
        }

        JsonArray notices = Jsons.array(data, "notices");
        bootstrapNotices = notices.deepCopy();
        if (userNoticesLoadedGeneration != generation) renderNotices(notices);
        renderFeatures(Jsons.object(data, "features"));
        loadUserNotices(generation);
    }

    private void loadUserNotices(long generation) {
        if (noticeRequestGeneration == generation) return;
        noticeRequestGeneration = generation;
        track(app().repository().get("/api/user/notices", new LinkedHashMap<>(), result -> {
            if (binding == null || generation != loadGeneration) return;
            JsonArray notices = bootstrapNotices;
            if (result.isSuccessful()) {
                notices = Jsons.array(result.dataObject(), "items");
                userNoticesLoadedGeneration = generation;
            }
            JsonArray displayNotices = notices;
            UiGuard.run(binding.getRoot(), "用户公告渲染", () -> {
                renderNotices(displayNotices);
                if (!popupShown) showPopupNotice(displayNotices);
            });
        }));
    }

    private void renderNotices(JsonArray notices) {
        String nextSnapshot = notices.toString();
        if (nextSnapshot.equals(noticeSnapshot)) return;
        noticeSnapshot = nextSnapshot;
        binding.noticeContainer.removeAllViews();
        for (JsonElement element : notices) {
            if (!element.isJsonObject()) continue;
            JsonObject notice = element.getAsJsonObject();
            LinearLayout row = new LinearLayout(requireContext());
            row.setOrientation(LinearLayout.VERTICAL);
            row.setPadding(0, dp(12), 0, dp(12));
            TextView title = new TextView(requireContext());
            RuntimeLanguage.setDynamicText(title, Jsons.string(notice, "title"));
            title.setTextAppearance(com.google.android.material.R.style.TextAppearance_Material3_TitleSmall);
            TextView content = new TextView(requireContext());
            RuntimeLanguage.setDynamicText(content, Jsons.string(notice, "content"));
            content.setMaxLines(3);
            content.setEllipsize(android.text.TextUtils.TruncateAt.END);
            content.setTextColor(requireContext().getColor(R.color.on_surface_variant));
            content.setPadding(0, dp(4), 0, 0);
            TextView date = new TextView(requireContext());
            RuntimeLanguage.setDynamicText(date, Jsons.string(notice, "created_at"));
            date.setTextAppearance(com.google.android.material.R.style.TextAppearance_Material3_LabelSmall);
            date.setTextColor(requireContext().getColor(R.color.outline));
            date.setPadding(0, dp(5), 0, 0);
            row.addView(title);
            row.addView(content);
            row.addView(date);
            row.setOnClickListener(view -> new YiyunyingDialogBuilder(requireContext())
                .setBusinessTitle(Jsons.string(notice, "title"))
                .setBusinessMessage(Jsons.string(notice, "content"))
                .setPositiveButton("知道了", null).show());
            binding.noticeContainer.addView(row, new LinearLayout.LayoutParams(-1, -2));
            View divider = new View(requireContext());
            divider.setBackgroundColor(requireContext().getColor(R.color.surface_container_high));
            binding.noticeContainer.addView(divider, new LinearLayout.LayoutParams(-1, dp(1)));
        }
        if (binding.noticeContainer.getChildCount() == 0) {
            TextView empty = new TextView(requireContext());
            empty.setText("暂无公告");
            empty.setTextColor(requireContext().getColor(R.color.on_surface_variant));
            empty.setPadding(0, dp(12), 0, dp(12));
            binding.noticeContainer.addView(empty);
        }
    }

    private void renderFeatures(JsonObject features) {
        String nextSnapshot = features.toString();
        if (nextSnapshot.equals(featureSnapshot)) return;
        featureSnapshot = nextSnapshot;
        binding.featureChips.removeAllViews();
        for (Map.Entry<String, JsonElement> entry : features.entrySet()) {
            boolean enabled = false;
            try {
                if (entry.getValue().isJsonObject()) {
                    enabled = booleanValue(entry.getValue().getAsJsonObject(), "enabled");
                } else {
                    enabled = entry.getValue().getAsBoolean();
                }
            } catch (RuntimeException ignored) {
                enabled = false;
            }
            if (!enabled) continue;
            String moduleId = featureModule(entry.getKey());
            if (moduleId.isEmpty()) continue;
            Chip chip = new Chip(requireContext());
            chip.setText(featureName(entry.getKey()));
            chip.setCheckable(false);
            chip.setOnClickListener(view -> UiGuard.run(view, "首页功能/" + moduleId,
                () -> host().openModule(moduleId)));
            binding.featureChips.addView(chip);
        }
    }

    private void showPopupNotice(JsonArray notices) {
        for (JsonElement element : notices) {
            if (!element.isJsonObject()) continue;
            JsonObject notice = element.getAsJsonObject();
            if (noticeDisplayStore.shouldShow(notice, app().session().loginMarker())) {
                popupShown = true;
                noticeDisplayStore.markShown(notice, app().session().loginMarker());
                new YiyunyingDialogBuilder(requireContext())
                    .setBusinessTitle(Jsons.string(notice, "title"))
                    .setBusinessMessage(Jsons.string(notice, "content"))
                    .setPositiveButton("知道了", null)
                    .show();
                return;
            }
        }
    }

    private String resolveUrl(String value) {
        if (value == null || value.isEmpty() || value.startsWith("http://") || value.startsWith("https://")) return value;
        try {
            HttpUrl resolved = HttpUrl.get(app().session().baseUrl()).resolve(value.startsWith("/") ? value.substring(1) : value);
            return resolved == null ? value : resolved.toString();
        } catch (RuntimeException exception) {
            return value;
        }
    }

    private String featureName(String key) {
        Map<String, String> names = new LinkedHashMap<>();
        names.put("user_profile", "个人资料");
        names.put("documents", "我的笔记");
        names.put("sign_invite", "签到邀请");
        names.put("notices", "通知公告");
        names.put("resources", "资源大厅");
        names.put("store", "应用商店");
        names.put("forum", "论坛社区");
        names.put("messages", "消息好友");
        names.put("chat_rooms", "聊天室");
        names.put("service", "联系客服");
        names.put("cards", "卡密");
        names.put("commerce", "商城");
        names.put("remote_files", "远程文件");
        names.put("shop", "余额商城");
        names.put("red_packets", "红包");
        names.put("lottery", "抽奖");
        names.put("feedback", "意见反馈");
        names.put("bot", "机器人问答");
        names.put("bounties", "悬赏");
        names.put("hierarchical_activities", "平台活动");
        names.put("votes", "投票活动");
        names.put("notifications", "通知");
        names.put("withdrawals", "提现");
        names.put("balance_document_purchase", "购买笔记额度");
        names.put("balance_membership_purchase", "购买会员");
        return names.getOrDefault(key, key.replace('_', ' '));
    }

    private String featureModule(String key) {
        Map<String, String> modules = new LinkedHashMap<>();
        modules.put("user_profile", "profile");
        modules.put("documents", "documents");
        modules.put("sign_invite", "wallet");
        modules.put("notices", "notifications");
        modules.put("resources", "resources");
        modules.put("store", "store_apps");
        modules.put("forum", "forum_posts");
        modules.put("messages", "conversations");
        modules.put("chat_rooms", "chat_rooms");
        modules.put("service", "service");
        modules.put("cards", "card_redeem");
        modules.put("remote_files", "remote_files");
        modules.put("shop", "shop_goods");
        modules.put("red_packets", "red_packets");
        modules.put("lottery", "lottery");
        modules.put("feedback", "feedbacks");
        modules.put("bot", "bot");
        modules.put("bounties", "bounties");
        modules.put("hierarchical_activities", "hierarchy_activities");
        modules.put("votes", "polls");
        modules.put("notifications", "notifications");
        modules.put("withdrawals", "withdrawals");
        modules.put("balance_document_purchase", "wallet");
        modules.put("balance_membership_purchase", "wallet");
        return modules.getOrDefault(key, "");
    }

    private static List<JsonObject> objects(JsonArray array) {
        List<JsonObject> result = new ArrayList<>();
        for (JsonElement element : array) {
            if (element.isJsonObject()) result.add(element.getAsJsonObject().deepCopy());
        }
        return result;
    }

    private static boolean booleanValue(JsonObject object, String key) {
        if (object == null || !object.has(key) || object.get(key).isJsonNull()) return false;
        try { return object.get(key).getAsBoolean(); } catch (RuntimeException ignored) { return false; }
    }

    private int dp(int value) { return Math.round(value * getResources().getDisplayMetrics().density); }

    @Override public void onDestroyView() {
        loadGeneration++;
        carouselHandler.removeCallbacks(carousel);
        binding = null;
        super.onDestroyView();
    }
}
