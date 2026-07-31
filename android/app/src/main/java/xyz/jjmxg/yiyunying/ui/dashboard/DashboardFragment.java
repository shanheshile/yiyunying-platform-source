package xyz.jjmxg.yiyunying.ui.dashboard;

import android.os.Bundle;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.widget.GridLayout;

import androidx.annotation.NonNull;
import androidx.annotation.Nullable;

import com.google.gson.JsonElement;
import com.google.gson.JsonObject;

import java.util.LinkedHashMap;
import java.util.Map;

import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.databinding.FragmentDashboardBinding;
import xyz.jjmxg.yiyunying.databinding.ItemKeyValueBinding;
import xyz.jjmxg.yiyunying.databinding.ItemMetricBinding;
import xyz.jjmxg.yiyunying.domain.module.ModuleSpec;
import xyz.jjmxg.yiyunying.domain.module.PathResolver;
import xyz.jjmxg.yiyunying.ui.common.BaseFragment;
import xyz.jjmxg.yiyunying.ui.common.DisplayText;
import xyz.jjmxg.yiyunying.ui.common.UiGuard;
import xyz.jjmxg.yiyunying.ui.permission.RolePermissionActivity;
import xyz.jjmxg.yiyunying.domain.Role;
import xyz.jjmxg.yiyunying.R;
import com.google.android.material.button.MaterialButton;

public final class DashboardFragment extends BaseFragment {
    private static final String ARG_MODULE_ID = "module_id";
    private static final String ARG_EMBEDDED = "embedded";
    private static final Map<String, String> LABELS = labels();
    private FragmentDashboardBinding binding;
    private ModuleSpec spec;
    private boolean loaded;

    public static DashboardFragment newInstance(String moduleId) {
        DashboardFragment fragment = new DashboardFragment();
        Bundle args = new Bundle();
        args.putString(ARG_MODULE_ID, moduleId);
        fragment.setArguments(args);
        return fragment;
    }

    public static DashboardFragment newEmbeddedInstance(String moduleId) {
        DashboardFragment fragment = newInstance(moduleId);
        fragment.requireArguments().putBoolean(ARG_EMBEDDED, true);
        return fragment;
    }

    @Override public void onCreate(@Nullable Bundle state) {
        super.onCreate(state);
        spec = app().modules().find(app().session().role(), requireArguments().getString(ARG_MODULE_ID, "dashboard"));
    }

    @Nullable
    @Override public View onCreateView(@NonNull LayoutInflater inflater, @Nullable ViewGroup container, @Nullable Bundle state) {
        binding = FragmentDashboardBinding.inflate(inflater, container, false);
        if (!requireArguments().getBoolean(ARG_EMBEDDED, false)) host().setPageTitle(spec.title());
        binding.swipeRefresh.setOnRefreshListener(() -> load(false));
        binding.retryButton.setOnClickListener(view -> load(true));
        renderRoleHeader();
        renderQuickActions();
        load(true);
        return binding.getRoot();
    }

    private void renderRoleHeader() {
        Role role = app().session().role();
        int actorLevel = app().session().actorLevel();
        if (role == Role.PLATFORM && actorLevel == 1) {
            binding.dashboardRoleBadge.setText("1 · 平台总控");
            binding.dashboardRoleTitle.setText("全平台经营总览");
            binding.scopeText.setText("管理全部授权平台、管理员、应用和用户；总控账号不受下级规则、会员与额度限制。");
        } else if (role == Role.PLATFORM) {
            binding.dashboardRoleBadge.setText("2 · 授权平台");
            binding.dashboardRoleTitle.setText("授权分支工作台");
            binding.scopeText.setText("管理当前授权分支内的管理员、应用、用户、内容、资产和服务。");
        } else {
            binding.dashboardRoleBadge.setText("3 · 管理员");
            binding.dashboardRoleTitle.setText("应用运营工作台");
            binding.scopeText.setText("当前应用 · " + app().session().selectedAppName());
        }
    }

    private void load(boolean fullLoading) {
        final String path;
        try {
            path = PathResolver.resolve(spec.listPath(), app().session(), null);
        } catch (IllegalArgumentException exception) {
            host().requestAppSelection();
            return;
        }
        if (fullLoading && !loaded) binding.progress.setVisibility(View.VISIBLE);
        track(app().repository().get(path, new LinkedHashMap<>(), result -> {
            if (binding == null) return;
            binding.progress.setVisibility(View.GONE);
            binding.swipeRefresh.setRefreshing(false);
            if (!result.isSuccessful()) {
                if (result.isAuthenticationFailure()) { host().onAuthenticationExpired(); return; }
                binding.errorMessage.setText(result.message().isEmpty() ? "数据总览加载失败" : result.message());
                binding.errorState.setVisibility(View.VISIBLE);
                binding.content.setVisibility(View.GONE);
                return;
            }
            loaded = true;
            binding.errorState.setVisibility(View.GONE);
            binding.content.setVisibility(View.VISIBLE);
            UiGuard.run(binding.getRoot(), "数据总览渲染", () -> render(result.dataObject()));
        }));
    }

    private void render(JsonObject data) {
        JsonObject scope = Jsons.object(data, "scope");
        if (!scope.entrySet().isEmpty()) {
            String level = Jsons.string(scope, "actor_level");
            binding.scopeText.setText(("1".equals(level) ? "一级总控 · 永久会员 · 无限余额、应用和文档额度" : "二级授权平台 · 当前独立分支")
                + (scope.has("platform_id") && !scope.get("platform_id").isJsonNull() ? " · 平台 #" + Jsons.string(scope, "platform_id") : ""));
        } else {
            binding.scopeText.setText("当前应用 · " + app().session().selectedAppName());
        }
        binding.metricsGrid.removeAllViews();
        JsonObject summary = Jsons.object(data, "summary");
        for (Map.Entry<String, JsonElement> entry : summary.entrySet()) addMetric(entry.getKey(), entry.getValue());
        binding.financeContainer.removeAllViews();
        addKeyValues(Jsons.object(data, "finance"));
        addKeyValues(Jsons.object(data, "api"));
        binding.chart.setData(Jsons.array(data, "daily"));
    }

    private void renderQuickActions() {
        binding.quickActions.removeAllViews();
        addQuickAction("我的权限", "__my_permissions", R.drawable.ic_settings);
        if (app().session().role() == Role.PLATFORM) {
            if (app().session().actorLevel() == 1) {
                addQuickAction("授权平台", "operators", R.drawable.ic_users);
                addQuickAction("管理员账号", "admins", R.drawable.ic_person);
                addQuickAction("全部应用", "apps", R.drawable.ic_apps);
                addQuickAction("强制治理", "governance", R.drawable.ic_settings);
                addQuickAction("注册赠送规则", "settings", R.drawable.ic_wallet);
                addQuickAction("软件更新", "software_updates", R.drawable.ic_refresh);
            } else {
                addQuickAction("管理员账号", "admins", R.drawable.ic_users);
                addQuickAction("全部应用", "apps", R.drawable.ic_apps);
                addQuickAction("注册赠送规则", "settings", R.drawable.ic_settings);
                addQuickAction("层级活动", "hierarchy_activities", R.drawable.ic_wallet);
                addQuickAction("平台交流", "platform_community", R.drawable.ic_forum);
                addQuickAction("软件更新", "software_updates", R.drawable.ic_refresh);
            }
        } else {
            addQuickAction("我的应用", "apps", R.drawable.ic_apps);
            addQuickAction("用户账号", "users", R.drawable.ic_users);
            addQuickAction("文档管理", "documents", R.drawable.ic_document);
            addQuickAction("公告管理", "notices", R.drawable.ic_content);
            addQuickAction("群聊与聊天室", "chat_rooms", R.drawable.ic_chat);
            addQuickAction("应用设置", "settings", R.drawable.ic_settings);
        }
    }

    private void addQuickAction(String title, String moduleId, int icon) {
        MaterialButton button = new MaterialButton(requireContext(), null, com.google.android.material.R.attr.materialButtonOutlinedStyle);
        button.setText(title);
        button.setIconResource(icon);
        button.setIconGravity(MaterialButton.ICON_GRAVITY_TOP);
        button.setGravity(android.view.Gravity.CENTER);
        button.setIconSize(dp(24));
        button.setIconPadding(dp(6));
        button.setMaxLines(2);
        button.setTextSize(13f);
        button.setInsetTop(0);
        button.setInsetBottom(0);
        button.setAllCaps(false);
        button.setOnClickListener(view -> {
            if ("__my_permissions".equals(moduleId)) RolePermissionActivity.openSelf(requireContext());
            else host().openModule(moduleId);
        });
        GridLayout.LayoutParams params = new GridLayout.LayoutParams();
        params.width = 0; params.height = dp(84);
        params.columnSpec = GridLayout.spec(GridLayout.UNDEFINED, 1f);
        params.setMargins(dp(3), dp(3), dp(3), dp(3));
        binding.quickActions.addView(button, params);
    }

    private void addMetric(String key, JsonElement value) {
        ItemMetricBinding item = ItemMetricBinding.inflate(getLayoutInflater(), binding.metricsGrid, false);
        item.label.setText(label(key));
        item.value.setText(DisplayText.value(value));
        String target = metricTarget(key);
        ModuleSpec targetSpec = target == null ? null : app().modules().find(app().session().role(), target);
        if (targetSpec != null) {
            item.getRoot().setClickable(true);
            item.getRoot().setFocusable(true);
            item.getRoot().setContentDescription(label(key) + " " + DisplayText.value(value) + "，点击查看详情");
            item.getRoot().setStrokeWidth(dp(1));
            item.getRoot().setStrokeColor(requireContext().getColor(R.color.outline));
            item.getRoot().setOnClickListener(view -> host().openModule(target));
        }
        GridLayout.LayoutParams params = new GridLayout.LayoutParams();
        params.width = 0;
        params.height = dp(112);
        params.columnSpec = GridLayout.spec(GridLayout.UNDEFINED, 1f);
        params.setMargins(dp(3), dp(3), dp(3), dp(3));
        binding.metricsGrid.addView(item.getRoot(), params);
        item.getRoot().setAlpha(0f);
        item.getRoot().setTranslationY(dp(8));
        item.getRoot().animate().alpha(1f).translationY(0f).setDuration(220L)
            .setStartDelay(binding.metricsGrid.getChildCount() * 20L).start();
    }

    private String metricTarget(String key) {
        if ("operators".equals(key)) return "operators";
        if (key.contains("admin")) return "admins";
        if ("apps".equals(key)) return "apps";
        if (key.contains("user")) return "users";
        if (key.contains("document")) return "documents";
        if (key.contains("resource")) return "resources";
        if (key.contains("forum")) return "forum_posts";
        if (key.contains("message")) return "messages";
        if (key.contains("service")) return "service_sessions";
        if (key.contains("order")) return "orders";
        if (key.contains("feedback")) return "feedbacks";
        if (key.contains("upload")) return "uploads";
        if (key.contains("notice")) return "notices";
        if (key.contains("version")) return app().session().role() == Role.PLATFORM ? "software_updates" : "versions";
        if (key.contains("request") || key.contains("error")) return "api_logs";
        return null;
    }

    private void addKeyValues(JsonObject object) {
        for (Map.Entry<String, JsonElement> entry : object.entrySet()) {
            ItemKeyValueBinding row = ItemKeyValueBinding.inflate(getLayoutInflater(), binding.financeContainer, false);
            row.key.setText(label(entry.getKey()));
            row.value.setText(DisplayText.value(entry.getValue()));
            binding.financeContainer.addView(row.getRoot());
        }
    }

    private String label(String key) {
        return LABELS.getOrDefault(key, DisplayText.label(key));
    }

    private int dp(int value) { return Math.round(value * getResources().getDisplayMetrics().density); }

    @Override public void onDestroyView() { binding = null; super.onDestroyView(); }

    private static Map<String, String> labels() {
        Map<String, String> labels = new LinkedHashMap<>();
        labels.put("operators", "授权平台");
        labels.put("admins", "管理员");
        labels.put("active_admins_7d", "近 7 日活跃管理员");
        labels.put("expired_admins", "已到期管理员");
        labels.put("apps", "应用");
        labels.put("users", "用户");
        labels.put("active_users", "正常用户");
        labels.put("active_users_7d", "近 7 日活跃用户");
        labels.put("documents", "文档");
        labels.put("document_shares", "文档分享");
        labels.put("resources", "资源");
        labels.put("forum_posts", "论坛帖子");
        labels.put("messages", "消息");
        labels.put("service_open", "待处理客服");
        labels.put("card_redeems", "卡密兑换");
        labels.put("orders", "订单");
        labels.put("paid_orders", "已支付订单");
        labels.put("feedback_pending", "待处理反馈");
        labels.put("uploads", "上传文件");
        labels.put("notices", "公告");
        labels.put("versions", "发布版本");
        labels.put("today_admin_registrations", "今日管理员注册");
        labels.put("today_admin_logins", "今日管理员登录");
        labels.put("paid_amount", "实付金额");
        labels.put("paid_count", "支付笔数");
        labels.put("balance_exchange_orders", "余额兑换订单");
        labels.put("balance_exchange_amount", "兑换消耗余额");
        labels.put("requests", "接口请求");
        labels.put("errors", "接口错误");
        labels.put("avg_duration_ms", "平均耗时 ms");
        return labels;
    }
}
