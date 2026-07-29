package xyz.jjmxg.yiyunying.ui.permission;

import android.content.Context;
import android.content.Intent;
import android.graphics.drawable.GradientDrawable;
import android.os.Bundle;
import android.text.Editable;
import android.text.TextWatcher;
import android.view.Gravity;
import android.view.View;
import android.view.ViewGroup;
import android.widget.LinearLayout;
import android.widget.TextView;

import androidx.core.content.ContextCompat;

import com.google.android.material.materialswitch.MaterialSwitch;
import com.google.android.material.snackbar.Snackbar;
import com.google.gson.JsonArray;
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
import xyz.jjmxg.yiyunying.databinding.ActivityRolePermissionBinding;
import xyz.jjmxg.yiyunying.domain.Role;
import xyz.jjmxg.yiyunying.ui.common.SystemInsetActivity;

/** Visual editor for the inherited level 1/2/3/4 permission chain. */
public final class RolePermissionActivity extends SystemInsetActivity {
    private static final int FILTER_ALL = 0;
    private static final int FILTER_ENABLED = 1;
    private static final int FILTER_DISABLED = 2;
    private static final int FILTER_LOCKED = 3;
    private static final String EXTRA_MODE = "permission_mode";
    private static final String EXTRA_TARGET_ID = "permission_target_id";
    private static final String EXTRA_APP_ID = "permission_app_id";
    private static final String EXTRA_NAME = "permission_name";
    private static final String EXTRA_ACCOUNT = "permission_account";
    private static final String MODE_SELF = "self";
    private static final String MODE_PLATFORM = "platform";
    private static final String MODE_ADMIN = "admin";
    private static final String MODE_USER = "user";

    private ActivityRolePermissionBinding binding;
    private RequestHandle request;
    private String mode;
    private String endpoint;
    private boolean readOnly;
    private long targetId;
    private long appId;
    private final Map<String, MaterialSwitch> editableSwitches = new LinkedHashMap<>();
    private final Map<String, Boolean> pendingPermissionValues = new LinkedHashMap<>();
    private JsonObject renderedData;
    private String permissionQuery = "";
    private int permissionFilter = FILTER_ALL;

    public static void openSelf(Context context) {
        open(context, MODE_SELF, 0, 0, "我的权限", "");
    }

    public static void openPlatform(Context context, long platformId, String name, String account) {
        open(context, MODE_PLATFORM, platformId, 0, name, account);
    }

    public static void openAdmin(Context context, long adminId, String name, String account) {
        open(context, MODE_ADMIN, adminId, 0, name, account);
    }

    public static void openUser(Context context, long appId, long userId, String name, String account) {
        open(context, MODE_USER, userId, appId, name, account);
    }

    private static void open(Context context, String mode, long targetId, long appId, String name, String account) {
        context.startActivity(new Intent(context, RolePermissionActivity.class)
            .putExtra(EXTRA_MODE, mode)
            .putExtra(EXTRA_TARGET_ID, targetId)
            .putExtra(EXTRA_APP_ID, appId)
            .putExtra(EXTRA_NAME, name == null ? "" : name)
            .putExtra(EXTRA_ACCOUNT, account == null ? "" : account));
    }

    @Override protected void onCreate(Bundle state) {
        super.onCreate(state);
        binding = ActivityRolePermissionBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());
        binding.toolbar.setNavigationOnClickListener(view -> finish());
        binding.saveButton.setOnClickListener(view -> save());
        binding.permissionSearch.addTextChangedListener(new TextWatcher() {
            @Override public void beforeTextChanged(CharSequence text, int start, int count, int after) {}
            @Override public void onTextChanged(CharSequence text, int start, int before, int count) {
                permissionQuery = text == null ? "" : text.toString().trim();
                renderPermissionGroups();
            }
            @Override public void afterTextChanged(Editable editable) {}
        });
        binding.permissionFilterGroup.setOnCheckedStateChangeListener((group, checkedIds) -> {
            int checkedId = checkedIds.isEmpty() ? R.id.filterAll : checkedIds.get(0);
            if (checkedId == R.id.filterEnabled) permissionFilter = FILTER_ENABLED;
            else if (checkedId == R.id.filterDisabled) permissionFilter = FILTER_DISABLED;
            else if (checkedId == R.id.filterLocked) permissionFilter = FILTER_LOCKED;
            else permissionFilter = FILTER_ALL;
            renderPermissionGroups();
        });

        mode = getIntent().getStringExtra(EXTRA_MODE);
        targetId = getIntent().getLongExtra(EXTRA_TARGET_ID, 0);
        appId = getIntent().getLongExtra(EXTRA_APP_ID, 0);
        if (!configureEndpoint()) {
            Snackbar.make(binding.getRoot(), "权限对象或应用信息不完整", Snackbar.LENGTH_LONG).show();
            binding.getRoot().postDelayed(this::finish, 900);
            return;
        }
        showTargetFallback();
        load();
    }

    private boolean configureEndpoint() {
        if (MODE_SELF.equals(mode)) {
            readOnly = true;
            Role role = AppAccess.from(this).session().role();
            if (role == Role.PLATFORM) endpoint = "/api/platform/permissions";
            else if (role == Role.ADMIN) endpoint = "/api/admin/permissions";
            else endpoint = "/api/user/permissions";
            return true;
        }
        if (targetId <= 0) return false;
        if (MODE_PLATFORM.equals(mode)) {
            endpoint = "/api/platform/operators/" + targetId + "/permissions";
            return true;
        }
        if (MODE_ADMIN.equals(mode)) {
            endpoint = "/api/platform/admins/" + targetId + "/permissions";
            return true;
        }
        if (!MODE_USER.equals(mode) || appId <= 0) return false;
        Role role = AppAccess.from(this).session().role();
        endpoint = role == Role.ADMIN
            ? "/api/admin/apps/" + appId + "/users/" + targetId + "/permissions"
            : "/api/platform/apps/" + appId + "/users/" + targetId + "/permissions";
        return true;
    }

    private void showTargetFallback() {
        String name = getIntent().getStringExtra(EXTRA_NAME);
        String account = getIntent().getStringExtra(EXTRA_ACCOUNT);
        RuntimeLanguage.setDynamicText(binding.targetName,
            name == null || name.isEmpty() ? "权限对象" : name);
        RuntimeLanguage.setDynamicText(binding.targetAccount, account == null ? "" : account);
        binding.targetRole.setText(levelLabel(expectedLevel()));
        binding.summaryText.setText("正在读取最终生效权限…");
        binding.roleChain.setText(roleChainText(expectedLevel(), currentActorLevel()));
        binding.managementScope.setText(managementScopeText(expectedLevel(), currentActorLevel(), MODE_SELF.equals(mode)));
    }

    private int expectedLevel() {
        if (MODE_SELF.equals(mode)) return currentActorLevel();
        if (MODE_PLATFORM.equals(mode)) return 2;
        if (MODE_ADMIN.equals(mode)) return 3;
        return 4;
    }

    private int currentActorLevel() {
        Role role = AppAccess.from(this).session().role();
        if (role == Role.PLATFORM) return AppAccess.from(this).session().actorLevel();
        if (role == Role.ADMIN) return 3;
        return 4;
    }

    private void load() {
        setLoading(true);
        request = AppAccess.from(this).repository().get(endpoint, new LinkedHashMap<>(), result -> {
            request = null;
            if (binding == null || isFinishing() || isDestroyed()) return;
            setLoading(false);
            if (!result.isSuccessful()) {
                Snackbar.make(binding.getRoot(), result.message().isEmpty()
                    ? "权限信息加载失败" : result.message(), Snackbar.LENGTH_LONG).show();
                return;
            }
            render(result.dataObject());
        });
    }

    private void render(JsonObject data) {
        renderedData = data.deepCopy();
        JsonObject target = Jsons.object(data, "target");
        JsonObject summary = Jsons.object(data, "summary");
        JsonObject accessState = Jsons.object(data, "access_state");
        String name = first(Jsons.string(target, "name"), getIntent().getStringExtra(EXTRA_NAME), "权限对象");
        String account = first(Jsons.string(target, "account"), getIntent().getStringExtra(EXTRA_ACCOUNT), "");
        int level = Jsons.intValue(target, "level", expectedLevel());
        int actorLevel = Jsons.intValue(data, "actor_level", currentActorLevel());
        boolean unlimited = bool(accessState, "unlimited", false);
        RuntimeLanguage.setDynamicText(binding.targetName, name);
        RuntimeLanguage.setDynamicText(binding.targetAccount, account);
        binding.targetRole.setText(levelLabel(level) + (unlimited
            ? " · 永久全权"
            : readOnly ? " · 查看最终权限" : " · 管理允许范围"));
        if (unlimited) {
            binding.summaryText.setText("总控账号不受会员、余额、额度和下级权限规则限制 · "
                + Jsons.intValue(summary, "enabled", 0) + " 项顶级权限全部生效");
            binding.inheritanceHint.setText("一级平台总控拥有系统最终管理权。此页只读展示系统内置权限，不能被二、三、四级账号反向限制。");
            binding.permissionLegend.setText("所有项目均为系统内置永久权限：本级配置和最终结果都为允许，来源固定为系统总控。");
        } else {
            binding.summaryText.setText("已启用 " + Jsons.intValue(summary, "enabled", 0)
                + " / " + Jsons.intValue(summary, "total", 0)
                + " · 已关闭 " + Jsons.intValue(summary, "disabled", 0)
                + " · 上级锁定 " + Jsons.intValue(summary, "locked", 0));
            binding.inheritanceHint.setText("上级强制规则优先于下级自定义。被锁定的权限会显示来源和原因，不能在本级绕过。");
            binding.permissionLegend.setText("每项分别展示：本级配置、最终结果、授权来源、锁定原因。只有标记为“可修改”的开关可以保存。");
        }
        binding.roleChain.setText(roleChainText(level, actorLevel));
        binding.managementScope.setText(managementScopeText(level, actorLevel, MODE_SELF.equals(mode)));

        pendingPermissionValues.clear();
        JsonArray groups = Jsons.array(data, "groups");
        for (JsonElement groupElement : groups) {
            if (!groupElement.isJsonObject()) continue;
            JsonObject group = groupElement.getAsJsonObject();
            for (JsonElement itemElement : Jsons.array(group, "items")) {
                if (!itemElement.isJsonObject()) continue;
                JsonObject item = itemElement.getAsJsonObject();
                if (bool(item, "editable", false) && !readOnly) {
                    String code = Jsons.string(item, "code");
                    if (!code.isEmpty()) pendingPermissionValues.put(code,
                        bool(item, "configured_enabled", false));
                }
            }
        }
        renderPermissionGroups();
        RuntimeLanguage.applyTree(this, binding.getRoot());
    }

    private void renderPermissionGroups() {
        if (binding == null || renderedData == null) return;
        editableSwitches.clear();
        binding.groupsContainer.removeAllViews();
        int shown = 0;
        for (JsonElement groupElement : Jsons.array(renderedData, "groups")) {
            if (!groupElement.isJsonObject()) continue;
            JsonObject group = groupElement.getAsJsonObject();
            List<JsonObject> matches = new ArrayList<>();
            for (JsonElement itemElement : Jsons.array(group, "items")) {
                if (!itemElement.isJsonObject()) continue;
                JsonObject item = itemElement.getAsJsonObject();
                if (matchesPermission(item)) matches.add(item);
            }
            if (matches.isEmpty()) continue;
            addGroupTitle(Jsons.string(group, "title"));
            for (JsonObject item : matches) addPermission(item);
            shown += matches.size();
        }
        if (shown == 0) addEmptyState();
        binding.saveButton.setVisibility(readOnly || pendingPermissionValues.isEmpty()
            ? View.GONE : View.VISIBLE);
    }

    private boolean matchesPermission(JsonObject item) {
        boolean effective = bool(item, "effective_enabled", false);
        boolean locked = bool(item, "locked", false);
        if (permissionFilter == FILTER_ENABLED && !effective) return false;
        if (permissionFilter == FILTER_DISABLED && effective) return false;
        if (permissionFilter == FILTER_LOCKED && !locked) return false;
        if (permissionQuery.isEmpty()) return true;
        String query = permissionQuery.toLowerCase(Locale.ROOT);
        String searchable = (Jsons.string(item, "title") + " "
            + Jsons.string(item, "description") + " "
            + Jsons.string(item, "code") + " "
            + Jsons.string(item, "source") + " "
            + Jsons.string(item, "lock_reason")).toLowerCase(Locale.ROOT);
        return searchable.contains(query);
    }

    private void addGroupTitle(String title) {
        TextView heading = new TextView(this);
        heading.setText(title.isEmpty() ? "其他权限" : title);
        heading.setTextAppearance(com.google.android.material.R.style.TextAppearance_Material3_TitleMedium);
        heading.setTextColor(ContextCompat.getColor(this, R.color.on_surface));
        LinearLayout.LayoutParams params = fullWidthParams();
        params.topMargin = dp(20);
        params.bottomMargin = dp(8);
        binding.groupsContainer.addView(heading, params);
    }

    private void addPermission(JsonObject item) {
        String code = Jsons.string(item, "code");
        boolean configured = pendingPermissionValues.containsKey(code)
            ? Boolean.TRUE.equals(pendingPermissionValues.get(code))
            : bool(item, "configured_enabled", false);
        boolean effective = bool(item, "effective_enabled", false);
        boolean editable = bool(item, "editable", false) && !readOnly;
        boolean locked = bool(item, "locked", false);

        LinearLayout card = new LinearLayout(this);
        card.setOrientation(LinearLayout.VERTICAL);
        card.setPadding(dp(14), dp(12), dp(14), dp(12));
        card.setBackground(panelBackground(locked));
        LinearLayout.LayoutParams cardParams = fullWidthParams();
        cardParams.bottomMargin = dp(8);

        LinearLayout top = new LinearLayout(this);
        top.setOrientation(LinearLayout.HORIZONTAL);
        top.setGravity(Gravity.CENTER_VERTICAL);

        LinearLayout textStack = new LinearLayout(this);
        textStack.setOrientation(LinearLayout.VERTICAL);
        LinearLayout.LayoutParams textParams = new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f);

        TextView title = new TextView(this);
        title.setText(first(Jsons.string(item, "title"), code, "未命名权限"));
        title.setTextAppearance(com.google.android.material.R.style.TextAppearance_Material3_TitleSmall);
        title.setTextColor(ContextCompat.getColor(this, R.color.on_surface));
        textStack.addView(title, fullWidthParams());

        TextView description = new TextView(this);
        description.setText(Jsons.string(item, "description"));
        description.setTextAppearance(com.google.android.material.R.style.TextAppearance_Material3_BodySmall);
        description.setTextColor(ContextCompat.getColor(this, R.color.on_surface_variant));
        description.setPadding(0, dp(3), dp(8), 0);
        textStack.addView(description, fullWidthParams());
        top.addView(textStack, textParams);

        MaterialSwitch toggle = new MaterialSwitch(this);
        toggle.setChecked(configured);
        toggle.setEnabled(editable);
        toggle.setContentDescription(title.getText());
        if (editable && !code.isEmpty()) {
            toggle.setOnCheckedChangeListener((button, checked) ->
                pendingPermissionValues.put(code, checked));
        }
        top.addView(toggle, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.WRAP_CONTENT,
            ViewGroup.LayoutParams.WRAP_CONTENT));
        card.addView(top, fullWidthParams());

        TextView badge = new TextView(this);
        badge.setText(editable ? "可修改" : locked ? "上级强制" : "只读查看");
        badge.setTextAppearance(com.google.android.material.R.style.TextAppearance_Material3_LabelMedium);
        badge.setTextColor(ContextCompat.getColor(this,
            editable ? R.color.on_primary_container : locked ? R.color.warning : R.color.on_surface_variant));
        badge.setGravity(Gravity.CENTER);
        badge.setPadding(dp(9), dp(4), dp(9), dp(4));
        badge.setBackground(badgeBackground(editable
            ? R.color.primary_container : locked ? R.color.surface_container_high : R.color.surface_container));
        LinearLayout.LayoutParams badgeParams = new LinearLayout.LayoutParams(
            ViewGroup.LayoutParams.WRAP_CONTENT, ViewGroup.LayoutParams.WRAP_CONTENT);
        badgeParams.topMargin = dp(8);
        card.addView(badge, badgeParams);

        addMetaLine(card, "本级配置", configured ? "开启" : "关闭", R.color.on_surface);
        addMetaLine(card, "最终结果",
            first(Jsons.string(item, "state_text"), effective ? "允许使用" : "已关闭"),
            effective ? R.color.success : R.color.error);
        String source = Jsons.string(item, "source");
        addMetaLine(card, "授权来源", source.isEmpty() ? "本级配置" : source, R.color.on_surface_variant);

        String reason = Jsons.string(item, "lock_reason");
        if (locked || !reason.isEmpty()) {
            addMetaLine(card, "锁定原因", reason.isEmpty() ? "上级强制锁定" : reason, R.color.warning);
        }
        if (!code.isEmpty() && editable) editableSwitches.put(code, toggle);
        binding.groupsContainer.addView(card, cardParams);
    }

    private void addEmptyState() {
        TextView empty = new TextView(this);
        empty.setText(permissionQuery.isEmpty() && permissionFilter == FILTER_ALL
            ? "当前账号暂无可展示的权限项"
            : "没有符合搜索或筛选条件的权限");
        empty.setGravity(Gravity.CENTER);
        empty.setPadding(dp(16), dp(36), dp(16), dp(36));
        empty.setTextColor(ContextCompat.getColor(this, R.color.on_surface_variant));
        binding.groupsContainer.addView(empty, fullWidthParams());
    }

    private void save() {
        if (readOnly || pendingPermissionValues.isEmpty() || request != null) return;
        JsonObject permissions = new JsonObject();
        for (Map.Entry<String, Boolean> entry : pendingPermissionValues.entrySet()) {
            JsonObject value = new JsonObject();
            value.addProperty("allowed", entry.getValue());
            permissions.add(entry.getKey(), value);
        }
        JsonObject body = new JsonObject();
        body.add("permissions", permissions);
        setLoading(true);
        request = AppAccess.from(this).repository().put(endpoint, body, result -> {
            request = null;
            if (binding == null || isFinishing() || isDestroyed()) return;
            setLoading(false);
            if (!result.isSuccessful()) {
                Snackbar.make(binding.getRoot(), result.message().isEmpty()
                    ? "权限保存失败" : result.message(), Snackbar.LENGTH_LONG).show();
                return;
            }
            Snackbar.make(binding.getRoot(), "权限配置已保存，最终状态已重新计算", Snackbar.LENGTH_SHORT).show();
            render(result.dataObject());
        });
    }

    private void setLoading(boolean loading) {
        if (binding == null) return;
        binding.progress.setVisibility(loading ? View.VISIBLE : View.INVISIBLE);
        binding.saveButton.setEnabled(!loading);
    }

    private GradientDrawable panelBackground(boolean locked) {
        GradientDrawable background = new GradientDrawable();
        background.setColor(ContextCompat.getColor(this,
            locked ? R.color.surface_container_high : R.color.surface_container));
        background.setCornerRadius(dp(8));
        background.setStroke(dp(1), ContextCompat.getColor(this,
            locked ? R.color.warning : R.color.outline_variant));
        return background;
    }

    private GradientDrawable badgeBackground(int colorResource) {
        GradientDrawable background = new GradientDrawable();
        background.setColor(ContextCompat.getColor(this, colorResource));
        background.setCornerRadius(dp(6));
        return background;
    }

    private void addMetaLine(LinearLayout card, String label, String value, int colorResource) {
        TextView line = new TextView(this);
        line.setText(label + "：" + value);
        line.setTextAppearance(com.google.android.material.R.style.TextAppearance_Material3_BodySmall);
        line.setTextColor(ContextCompat.getColor(this, colorResource));
        line.setPadding(0, dp(5), 0, 0);
        card.addView(line, fullWidthParams());
    }

    private LinearLayout.LayoutParams fullWidthParams() {
        return new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT,
            ViewGroup.LayoutParams.WRAP_CONTENT);
    }

    private static boolean bool(JsonObject object, String key, boolean fallback) {
        try {
            return object.has(key) && !object.get(key).isJsonNull() ? object.get(key).getAsBoolean() : fallback;
        } catch (RuntimeException ignored) {
            return fallback;
        }
    }

    private static String first(String... values) {
        for (String value : values) if (value != null && !value.isEmpty()) return value;
        return "";
    }

    private static String levelLabel(int level) {
        if (level == 1) return "1级平台总控";
        if (level == 2) return "2级授权平台";
        if (level == 3) return "3级管理员";
        return "4级用户";
    }

    private static String roleChainText(int targetLevel, int actorLevel) {
        return "权限链：1级平台总控 → 2级授权平台 → 3级管理员 → 4级用户\n"
            + "当前登录：" + levelLabel(actorLevel) + " · 页面对象：" + levelLabel(targetLevel);
    }

    private static String managementScopeText(int targetLevel, int actorLevel, boolean self) {
        if (self) {
            if (actorLevel == 1) return "管理范围：全部2、3、4级账号、全部应用及附属数据；不受下级规则反向限制。";
            if (actorLevel == 2) return "管理范围：仅本授权平台分支内的3级管理员、应用和4级用户；不能查看或修改其他2级分支。";
            if (actorLevel == 3) return "管理范围：仅本人创建或被授权的应用及其4级用户；不能管理1、2级账号。";
            return "管理范围：仅查看本人最终生效权限；不能修改上级、应用或其他用户权限。";
        }
        if (targetLevel == 2) return "当前操作：1级平台总控为2级授权平台配置其分支管理权限。";
        if (targetLevel == 3) return "当前操作：仅在所属分支内配置3级管理员权限，不能跨授权平台管理。";
        return "当前操作：仅配置目标用户在所属应用内的权限，不能影响其他应用或其他管理员的数据。";
    }
    private int dp(int value) {
        return Math.round(value * getResources().getDisplayMetrics().density);
    }

    @Override protected void onDestroy() {
        if (request != null) request.cancel();
        editableSwitches.clear();
        pendingPermissionValues.clear();
        renderedData = null;
        binding = null;
        super.onDestroy();
    }
}
