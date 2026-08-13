package xyz.jjmxg.yiyunying.ui.main;

import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.text.Editable;
import android.text.TextUtils;
import android.text.TextWatcher;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.widget.GridLayout;
import android.widget.LinearLayout;
import android.widget.TextView;

import androidx.annotation.NonNull;
import androidx.annotation.Nullable;

import com.google.android.material.button.MaterialButton;

import java.util.ArrayList;
import java.util.Arrays;
import java.util.HashMap;
import java.util.HashSet;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Locale;
import java.util.Map;
import java.util.Set;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.databinding.FragmentFeatureDirectoryBinding;
import xyz.jjmxg.yiyunying.domain.Role;
import xyz.jjmxg.yiyunying.domain.module.ModuleSpec;
import xyz.jjmxg.yiyunying.ui.common.BaseFragment;
import xyz.jjmxg.yiyunying.ui.common.GlassBottomSheet;

public final class FeatureDirectoryFragment extends BaseFragment {
    private static final long SEARCH_RENDER_DELAY_MS = 140L;
    private static final String ARG_MODE = "mode";
    private static final String ARG_EMBEDDED = "embedded";
    private static final String ARG_EXCLUDE_DASHBOARD = "exclude_dashboard";
    private FragmentFeatureDirectoryBinding binding;
    private final Handler renderHandler = new Handler(Looper.getMainLooper());
    private String lastRenderedQuery;
    private final Runnable renderSearch = () -> {
        if (binding == null) return;
        CharSequence value = binding.searchInput.getText();
        render(value == null ? "" : value.toString());
    };

    private static final List<String> MANAGEMENT_ORDER = Arrays.asList(
        "dashboard", "apps", "operators", "admins", "users", "user_tags",
        "software_updates", "versions", "maintenances", "remote_configs", "notices",
        "store_apps", "store_categories", "shop_goods", "exchange_products", "exchanges",
        "resources", "uploads", "documents", "remote_files", "forum_plates",
        "forum_categories", "forum_tags", "forum_structure_requests", "forum_moderators",
        "forum_posts", "forum_comments", "bounties", "bounty_categories",
        "bounty_category_requests", "poll_categories", "polls", "votes", "red_packets",
        "lottery", "friends", "friend_requests", "chat_rooms", "service_sessions",
        "messages", "ai_knowledge", "bot_qa", "feedbacks", "reports", "orders",
        "purchase_orders", "payment_channels", "withdrawals", "card_batches", "cards",
        "governance", "domains"
    );

    public static FeatureDirectoryFragment newInstance() { return new FeatureDirectoryFragment(); }

    public static FeatureDirectoryFragment newEmbeddedInstance(String mode) {
        return newEmbeddedInstance(mode, false);
    }

    public static FeatureDirectoryFragment newEmbeddedInstance(String mode, boolean excludeDashboard) {
        FeatureDirectoryFragment fragment = new FeatureDirectoryFragment();
        Bundle args = new Bundle();
        args.putString(ARG_MODE, mode);
        args.putBoolean(ARG_EMBEDDED, true);
        args.putBoolean(ARG_EXCLUDE_DASHBOARD, excludeDashboard);
        fragment.setArguments(args);
        return fragment;
    }

    @Nullable @Override
    public View onCreateView(@NonNull LayoutInflater inflater, @Nullable ViewGroup container, @Nullable Bundle state) {
        binding = FragmentFeatureDirectoryBinding.inflate(inflater, container, false);
        if (getArguments() == null || !getArguments().getBoolean(ARG_EMBEDDED, false)) {
            host().setPageTitle("全部功能");
        }
        renderContext();
        binding.searchInput.addTextChangedListener(new TextWatcher() {
            @Override public void beforeTextChanged(CharSequence value, int start, int count, int after) { }
            @Override public void onTextChanged(CharSequence value, int start, int before, int count) {
                renderHandler.removeCallbacks(renderSearch);
                renderHandler.postDelayed(renderSearch, SEARCH_RENDER_DELAY_MS);
            }
            @Override public void afterTextChanged(Editable value) { }
        });
        render("");
        return binding.getRoot();
    }

    private void renderContext() {
        String currentMode = mode();
        String title;
        String hint;
        if ("apps".equals(currentMode)) {
            title = "应用工作台";
            hint = "搜索应用、账号或系统";
        } else if ("source".equals(currentMode)) {
            title = "源码与资源";
            hint = "搜索源码、资源、文档或文件";
        } else if ("community".equals(currentMode)) {
            title = "交流与审核";
            hint = "搜索论坛、悬赏、消息或审核";
        } else if ("account".equals(currentMode)) {
            title = "我的账号";
            hint = "搜索权益、安全或个人工具";
        } else {
            title = "全部功能";
            hint = "搜索功能";
        }

        Role role = app().session().role();
        int actorLevel = app().session().actorLevel();
        if (role == Role.PLATFORM && actorLevel == 1) {
            binding.roleBadge.setText("1 · 平台总控");
            binding.directorySubtitle.setText("管理全部授权平台、管理员、应用、用户与生命周期；总控权限不受下级规则反向限制。");
        } else if (role == Role.PLATFORM) {
            binding.roleBadge.setText("2 · 授权平台");
            binding.directorySubtitle.setText("当前独立授权分支；管理所属管理员、应用、用户、内容与服务。");
        } else {
            binding.roleBadge.setText("3 · 管理员");
            String appName = app().session().selectedAppName();
            binding.directorySubtitle.setText((appName == null || appName.trim().isEmpty() ? "当前应用" : appName)
                + "；管理被授权的用户、内容、资产、运营与服务功能。");
        }
        binding.directoryTitle.setText(title);
        binding.searchLayout.setHint(hint);
    }

    private void render(String query) {
        if (binding == null) return;
        String normalizedQuery = query == null ? "" : query.trim();
        if (TextUtils.equals(lastRenderedQuery, normalizedQuery)) return;
        lastRenderedQuery = normalizedQuery;
        String keyword = normalizedQuery.toLowerCase(Locale.ROOT);
        binding.groupsContainer.removeAllViews();
        List<ModuleSpec> visible = new ArrayList<>();
        for (ModuleSpec module : app().modules().forRole(app().session().role())) {
            if ("home".equals(module.id())) continue;
            if (app().session().role() == Role.PLATFORM
                && !xyz.jjmxg.yiyunying.domain.AppEdition.canOpenPlatformModule(
                    app().session().actorLevel(), module.id())) continue;
            if ("dashboard".equals(module.id()) && excludeDashboard()) continue;
            if ("dashboard".equals(module.id()) && !"apps".equals(mode())) continue;
            if (app().session().role() == Role.USER && "blacklist".equals(module.id())) continue;
            if (!matchesMode(module)) continue;
            String searchable = (module.title() + " " + module.group() + " " + module.id()).toLowerCase(Locale.ROOT);
            if (!keyword.isEmpty() && !searchable.contains(keyword)) continue;
            visible.add(module);
        }
        sortForMode(visible);

        Map<String, GridLayout> groups = new LinkedHashMap<>();
        Set<String> renderedIds = new HashSet<>();
        for (ModuleSpec module : visible) {
            if (!renderedIds.add(module.id())) continue;
            String section = displayGroup(module);
            GridLayout grid = groups.get(section);
            if (grid == null) {
                TextView heading = new TextView(requireContext());
                heading.setText(section);
                heading.setTextAppearance(com.google.android.material.R.style.TextAppearance_Material3_TitleMedium);
                LinearLayout.LayoutParams headingParams = new LinearLayout.LayoutParams(-1, -2);
                headingParams.topMargin = groups.isEmpty() ? 4 : dp(20);
                headingParams.bottomMargin = dp(6);
                binding.groupsContainer.addView(heading, headingParams);
                grid = new GridLayout(requireContext());
                grid.setColumnCount(2);
                binding.groupsContainer.addView(grid, new LinearLayout.LayoutParams(-1, -2));
                groups.put(section, grid);
            }
            MaterialButton button = new MaterialButton(requireContext(), null, com.google.android.material.R.attr.materialButtonOutlinedStyle);
            button.setText(module.title());
            button.setIconResource(icon(module));
            button.setIconGravity(MaterialButton.ICON_GRAVITY_TOP);
            button.setGravity(android.view.Gravity.CENTER);
            button.setAllCaps(false);
            button.setMaxLines(2);
            button.setTextSize(13f);
            button.setIconSize(dp(24));
            button.setIconPadding(dp(6));
            button.setInsetTop(0);
            button.setInsetBottom(0);
            button.setMinWidth(0);
            GlassBottomSheet.styleActionButton(button, requireContext(), false, 13);
            button.setOnClickListener(view -> host().openModule(module.id()));
            GridLayout.LayoutParams params = new GridLayout.LayoutParams();
            params.width = 0;
            params.height = dp(84);
            params.columnSpec = GridLayout.spec(GridLayout.UNDEFINED, 1f);
            params.setMargins(dp(3), dp(3), dp(3), dp(3));
            grid.addView(button, params);
        }
        if (groups.isEmpty()) {
            TextView empty = new TextView(requireContext());
            empty.setText("没有找到相关功能");
            empty.setGravity(android.view.Gravity.CENTER);
            empty.setTextColor(requireContext().getColor(R.color.on_surface_variant));
            binding.groupsContainer.addView(empty, new LinearLayout.LayoutParams(-1, dp(120)));
        }
    }

    private void sortForMode(List<ModuleSpec> modules) {
        if ("all".equals(mode())) return;
        Map<String, Integer> rank = new HashMap<>();
        for (int index = 0; index < MANAGEMENT_ORDER.size(); index++) {
            rank.put(MANAGEMENT_ORDER.get(index), index);
        }
        modules.sort((left, right) -> {
            int leftRank = rank.containsKey(left.id()) ? rank.get(left.id()) : Integer.MAX_VALUE;
            int rightRank = rank.containsKey(right.id()) ? rank.get(right.id()) : Integer.MAX_VALUE;
            if (leftRank != rightRank) return Integer.compare(leftRank, rightRank);
            int groupOrder = Integer.compare(sectionRank(displayGroup(left)), sectionRank(displayGroup(right)));
            return groupOrder != 0 ? groupOrder : left.title().compareTo(right.title());
        });
    }

    private int sectionRank(String section) {
        if (section.startsWith("应用") || section.startsWith("示例") || section.startsWith("交流")
            || section.startsWith("账号")) return 0;
        if (section.startsWith("发布") || section.startsWith("文件") || section.startsWith("内容")
            || section.startsWith("资产")) return 1;
        if (section.startsWith("商业") || section.startsWith("审核") || section.startsWith("安全")) return 2;
        return 3;
    }

    private String displayGroup(ModuleSpec module) {
        String id = module.id().toLowerCase(Locale.ROOT);
        String current = mode();
        if ("apps".equals(current)) {
            if (containsAny(id, "app", "operator", "admin", "user", "domain", "governance")) return "应用与用户系统";
            if (containsAny(id, "update", "version", "maintenance", "remote_config", "notice")) return "发布与生命周期";
            if (containsAny(id, "store", "shop", "exchange", "payment", "withdraw", "order", "card", "lottery")) return "商业与资产系统";
            if (containsAny(id, "forum", "bount", "poll", "vote", "red_packet", "friend", "chat", "message", "service", "feedback", "report", "ai_", "bot_")) return "内容、沟通与服务";
            return "文件与其他系统";
        }
        if ("source".equals(current)) {
            if (containsAny(id, "resource", "store_app", "source", "template", "code", "sdk", "api_")) return "示例源码与资源";
            return "文件、文档与接口";
        }
        if ("community".equals(current)) {
            if (containsAny(id, "forum", "bount", "community", "poll", "vote", "moment")) return "交流与内容";
            if (containsAny(id, "report", "feedback", "moderator", "audit")) return "审核与反馈";
            return "消息、群聊与服务";
        }
        if ("account".equals(current)) {
            if (containsAny(id, "profile", "setting", "password", "token")) return "账号与安全";
            if (containsAny(id, "wallet", "membership", "level", "order", "document", "space", "asset")) return "资产与权益";
            return "个人工具";
        }
        return module.group();
    }

    private String mode() {
        return getArguments() == null ? "all" : getArguments().getString(ARG_MODE, "all");
    }

    private boolean excludeDashboard() {
        return getArguments() != null && getArguments().getBoolean(ARG_EXCLUDE_DASHBOARD, false);
    }

    private boolean matchesMode(ModuleSpec module) {
        String mode = mode();
        if ("all".equals(mode)) return true;
        String group = module.group();
        String id = module.id().toLowerCase(Locale.ROOT);
        if ("apps".equals(mode)) {
            return "dashboard".equals(id) || "组织".equals(group) || "应用".equals(group)
                || "用户".equals(group) || "治理".equals(group) || "计费".equals(group)
                || "生命周期".equals(group);
        }
        if ("source".equals(mode)) {
            return ManagementNavigationPolicy.sourceDirectoryModule(id, group);
        }
        if ("community".equals(mode)) {
            return "内容".equals(group) || "社区".equals(group) || "互动".equals(group)
                || "沟通".equals(group) || "服务".equals(group) || "消息".equals(group)
                || containsAny(id, "forum", "bounty", "poll", "moment", "chat", "friend", "notice");
        }
        return "账户".equals(group) || "资产".equals(group) || "工具".equals(group)
            || containsAny(id, "setting", "profile", "password", "token", "wallet", "membership", "level");
    }

    private boolean containsAny(String value, String... needles) {
        for (String needle : needles) if (value.contains(needle)) return true;
        return false;
    }

    private int icon(ModuleSpec module) {
        String id = module.id();
        if (id.contains("user") || id.contains("admin") || id.contains("operator")) return R.drawable.ic_users;
        if (id.contains("message") || id.contains("chat") || id.contains("friend")) return R.drawable.ic_chat;
        if (id.contains("forum") || id.contains("resource") || id.contains("community")) return R.drawable.ic_forum;
        if (id.contains("document") || id.contains("notice") || id.contains("file")) return R.drawable.ic_document;
        if (id.contains("setting") || id.contains("governance")) return R.drawable.ic_settings;
        if (id.contains("app")) return R.drawable.ic_apps;
        return R.drawable.ic_dashboard;
    }

    private int dp(int value) { return Math.round(value * getResources().getDisplayMetrics().density); }
    @Override public void onDestroyView() {
        renderHandler.removeCallbacks(renderSearch);
        lastRenderedQuery = null;
        binding = null;
        super.onDestroyView();
    }
}
