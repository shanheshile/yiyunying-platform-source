package xyz.jjmxg.yiyunying.ui.management;

import android.content.Context;
import android.content.Intent;
import android.os.Bundle;
import android.view.MenuItem;
import android.view.View;
import android.view.inputmethod.EditorInfo;
import android.widget.EditText;
import android.widget.LinearLayout;
import android.widget.TextView;

import androidx.recyclerview.widget.LinearLayoutManager;

import xyz.jjmxg.yiyunying.ui.common.YiyunyingDialogBuilder;
import com.google.android.material.snackbar.Snackbar;
import com.google.android.material.switchmaterial.SwitchMaterial;
import com.google.gson.JsonObject;

import java.util.LinkedHashMap;
import java.util.Map;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.core.AppAccess;
import xyz.jjmxg.yiyunying.core.RuntimeLanguage;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.data.api.RequestHandle;
import xyz.jjmxg.yiyunying.databinding.ActivityManagedCommunicationBinding;
import xyz.jjmxg.yiyunying.domain.Role;
import xyz.jjmxg.yiyunying.ui.chat.ChatAdapter;
import xyz.jjmxg.yiyunying.ui.common.RecordDetailDialog;

public final class ManagedCommunicationActivity extends xyz.jjmxg.yiyunying.ui.common.SystemInsetActivity {
    private static final String EXTRA_APP_ID = "app_id";
    private static final String EXTRA_USER_ID = "user_id";
    private static final String EXTRA_TYPE = "channel_type";
    private static final String EXTRA_CHANNEL_ID = "channel_id";
    private static final String EXTRA_TITLE = "title";

    private ActivityManagedCommunicationBinding binding;
    private ChatAdapter adapter;
    private RequestHandle request;
    private RequestHandle sendRequest;
    private RequestHandle policyRequest;
    private RequestHandle policySaveRequest;
    private RequestHandle updateRequest;
    private RequestHandle deleteRequest;
    private JsonObject policyData = new JsonObject();
    private JsonObject viewContext = new JsonObject();
    private LinearLayoutManager layoutManager;
    private boolean canSend;
    private boolean firstLoad = true;
    private boolean scrollAfterLoad;

    public static void open(Context context, long appId, long userId, String type, long channelId, String title) {
        context.startActivity(new Intent(context, ManagedCommunicationActivity.class)
            .putExtra(EXTRA_APP_ID, appId).putExtra(EXTRA_USER_ID, userId)
            .putExtra(EXTRA_TYPE, type).putExtra(EXTRA_CHANNEL_ID, channelId).putExtra(EXTRA_TITLE, title));
    }

    @Override protected void onCreate(Bundle state) {
        super.onCreate(state);
        binding = ActivityManagedCommunicationBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());
        xyz.jjmxg.yiyunying.core.RuntimeLanguage.setDynamicToolbarTitle(
            binding.toolbar,
            getIntent().getStringExtra(EXTRA_TITLE)
        );
        binding.toolbar.setSubtitle("管理员视角 · 按目标用户的对话位置显示");
        binding.toolbar.setNavigationOnClickListener(view -> finish());
        MenuItem policyMenu = binding.toolbar.getMenu().add("接管权限");
        policyMenu.setIcon(R.drawable.ic_settings);
        policyMenu.setShowAsAction(MenuItem.SHOW_AS_ACTION_ALWAYS);
        binding.toolbar.setOnMenuItemClickListener(item -> { showPolicyDialog(); return true; });

        binding.composer.setVisibility(View.GONE);
        binding.readOnlyNotice.setVisibility(View.VISIBLE);
        binding.sendButton.setOnClickListener(view -> send());
        binding.policyStatus.setOnClickListener(view -> showPolicyDialog());
        binding.viewContext.setOnClickListener(view -> {
            if (viewContext.entrySet().isEmpty()) return;
            RecordDetailDialog.show(this, "具体对话信息", viewContext);
        });
        binding.searchButton.setOnClickListener(view -> load());
        binding.searchInput.setOnEditorActionListener((view, actionId, event) -> {
            if (actionId == EditorInfo.IME_ACTION_SEARCH) { load(); return true; }
            return false;
        });
        binding.searchFilterGroup.addOnButtonCheckedListener((group, checkedId, checked) -> {
            if (checked) load();
        });

        adapter = new ChatAdapter(targetUserId(), Role.USER, this::showMessageActions);
        adapter.setManagedAppId(getIntent().getLongExtra(EXTRA_APP_ID, 0));
        layoutManager = new LinearLayoutManager(this);
        layoutManager.setStackFromEnd(true);
        binding.recycler.setLayoutManager(layoutManager);
        binding.recycler.setAdapter(adapter);
        loadPolicy();
        load();
    }

    private void load() {
        if (request != null) request.cancel();
        int savedPosition = layoutManager == null ? -1 : layoutManager.findFirstVisibleItemPosition();
        View savedView = savedPosition < 0 || layoutManager == null ? null : layoutManager.findViewByPosition(savedPosition);
        int savedOffset = savedView == null ? 0 : savedView.getTop() - binding.recycler.getPaddingTop();
        binding.progress.setVisibility(View.VISIBLE);
        LinkedHashMap<String, String> query = new LinkedHashMap<>();
        query.put("channel_type", channelType());
        query.put("channel_id", String.valueOf(channelId()));
        query.put("limit", "100");
        String keyword = binding.searchInput.getText() == null ? "" : binding.searchInput.getText().toString().trim();
        if (!keyword.isEmpty()) query.put("keyword", keyword);
        query.put("content_filter", searchFilter());
        request = AppAccess.from(this).repository().get(communicationPath(), query, result -> {
            request = null;
            if (binding == null) return;
            binding.progress.setVisibility(View.INVISIBLE);
            if (!result.isSuccessful()) {
                Snackbar.make(binding.getRoot(), result.message().isEmpty() ? "通信记录加载失败" : result.message(), Snackbar.LENGTH_LONG).show();
                return;
            }
            adapter.submit(result.objectItems());
            renderViewContext(Jsons.object(result.dataObject(), "view_context"));
            String notice = Jsons.string(result.dataObject(), "audit_notice");
            if (!notice.isEmpty()) RuntimeLanguage.setDynamicText(binding.auditNotice, notice);
            JsonObject returnedPolicy = Jsons.object(result.dataObject(), "takeover_policy");
            if (!returnedPolicy.entrySet().isEmpty()) renderPolicy(returnedPolicy);
            if (adapter.getItemCount() > 0) {
                if (firstLoad || scrollAfterLoad || savedPosition < 0) {
                    binding.recycler.scrollToPosition(adapter.getItemCount() - 1);
                } else {
                    layoutManager.scrollToPositionWithOffset(Math.min(savedPosition, adapter.getItemCount() - 1), savedOffset);
                }
            }
            firstLoad = false;
            scrollAfterLoad = false;
        });
    }

    private void renderViewContext(JsonObject context) {
        viewContext = context == null ? new JsonObject() : context.deepCopy();
        String summary = Jsons.string(viewContext, "summary");
        String description = Jsons.string(viewContext, "description");
        if (summary.isEmpty()) {
            binding.viewContext.setText("管理员视角 · 正在查看选中的具体对话");
            return;
        }
        RuntimeLanguage.setDynamicText(binding.viewContext,
            RuntimeLanguage.translate(this, "管理员视角") + " · " + summary
                + (description.isEmpty() ? "" : "\n" + description));
        RuntimeLanguage.protectDynamicText(binding.toolbar);
        binding.toolbar.setSubtitle(RuntimeLanguage.translate(this, "管理员视角") + " · " + summary);
    }

    private void loadPolicy() {
        if (policyRequest != null) policyRequest.cancel();
        policyRequest = AppAccess.from(this).repository().get(policyPath(), new LinkedHashMap<>(), result -> {
            policyRequest = null;
            if (binding == null) return;
            if (!result.isSuccessful()) {
                binding.policyStatus.setText(result.message().isEmpty() ? "接管权限读取失败" : result.message());
                return;
            }
            renderPolicy(result.dataObject());
        });
    }

    private void renderPolicy(JsonObject data) {
        policyData = data.deepCopy();
        JsonObject effective = Jsons.object(data, "effective");
        boolean channelEnabled = bool(effective, channelType() + "_enabled", false);
        canSend = bool(effective, "can_send", false) && channelEnabled;
        boolean canView = bool(effective, "can_view", false) && channelEnabled;
        boolean editable = bool(data, "editable", false);
        binding.composer.setVisibility(canSend ? View.VISIBLE : View.GONE);
        binding.readOnlyNotice.setVisibility(canSend ? View.GONE : View.VISIBLE);
        binding.sendButton.setEnabled(canSend);
        String state = canView ? (canSend ? "可查看、可发送系统消息" : "可查看、不可发言") : "当前通信类型已停用接管";
        binding.policyStatus.setText(state + (editable ? " · 点击配置权限" : " · 策略由上级强制锁定"));
    }

    private void send() {
        if (sendRequest != null || !canSend) return;
        String content = binding.messageInput.getText() == null ? "" : binding.messageInput.getText().toString().trim();
        if (content.isEmpty()) {
            binding.messageInput.setError("请输入要发送的内容");
            return;
        }
        JsonObject body = new JsonObject();
        body.addProperty("channel_type", channelType());
        body.addProperty("channel_id", channelId());
        body.addProperty("content", content);
        binding.progress.setVisibility(View.VISIBLE);
        sendRequest = AppAccess.from(this).repository().post(communicationPath() + "/send", body, result -> {
            sendRequest = null;
            if (binding == null) return;
            binding.progress.setVisibility(View.INVISIBLE);
            if (!result.isSuccessful()) {
                Snackbar.make(binding.getRoot(), result.message().isEmpty() ? "系统消息发送失败" : result.message(), Snackbar.LENGTH_LONG).show();
                return;
            }
            binding.messageInput.setText("");
            Snackbar.make(binding.getRoot(), "已作为系统消息发送，接管者不会出现在成员列表", Snackbar.LENGTH_SHORT).show();
            scrollAfterLoad = true;
            load();
        });
    }

    private void showMessageActions(JsonObject message) {
        long messageId = Jsons.longValue(message, "id");
        if (messageId <= 0) {
            RecordDetailDialog.show(this, "消息审计信息", message);
            return;
        }
        String[] actions = {"查看审计详情", "修改聊天内容", "删除聊天内容"};
        new YiyunyingDialogBuilder(this)
            .setTitle("管理这条消息")
            .setItems(actions, (dialog, which) -> {
                if (which == 0) RecordDetailDialog.show(this, "消息审计信息", message);
                else if (which == 1) showEditMessageDialog(message);
                else confirmDeleteMessage(messageId);
            })
            .show();
    }

    private void showEditMessageDialog(JsonObject message) {
        EditText input = new EditText(this);
        input.setText(Jsons.string(message, "content"));
        input.setSelection(input.length());
        input.setMinLines(3);
        input.setMaxLines(8);
        input.setPadding(dp(20), dp(12), dp(20), dp(12));
        new YiyunyingDialogBuilder(this)
            .setTitle("修改聊天内容")
            .setMessage("修改会写入管理审计日志，成员端将显示修改后的内容。")
            .setView(input)
            .setNegativeButton("取消", null)
            .setPositiveButton("保存", (dialog, which) -> updateMessage(Jsons.longValue(message, "id"), input.getText().toString().trim()))
            .show();
    }

    private void updateMessage(long messageId, String content) {
        if (updateRequest != null) return;
        if (content.isEmpty()) {
            Snackbar.make(binding.getRoot(), "聊天内容不能为空", Snackbar.LENGTH_SHORT).show();
            return;
        }
        JsonObject body = communicationBody();
        body.addProperty("content", content);
        binding.progress.setVisibility(View.VISIBLE);
        updateRequest = AppAccess.from(this).repository().put(communicationPath() + "/" + messageId, body, result -> {
            updateRequest = null;
            if (binding == null) return;
            binding.progress.setVisibility(View.INVISIBLE);
            Snackbar.make(binding.getRoot(), result.isSuccessful() ? "聊天内容已修改并记录审计" : fallback(result.message(), "聊天内容修改失败"), Snackbar.LENGTH_LONG).show();
            if (result.isSuccessful()) load();
        });
    }

    private void confirmDeleteMessage(long messageId) {
        new YiyunyingDialogBuilder(this)
            .setTitle("删除聊天内容")
            .setMessage("删除会同步到该会话并保留管理审计记录，是否继续？")
            .setNegativeButton("取消", null)
            .setPositiveButton("删除", (dialog, which) -> deleteMessage(messageId))
            .show();
    }

    private void deleteMessage(long messageId) {
        if (deleteRequest != null) return;
        binding.progress.setVisibility(View.VISIBLE);
        deleteRequest = AppAccess.from(this).repository().delete(communicationPath() + "/" + messageId, communicationBody(), result -> {
            deleteRequest = null;
            if (binding == null) return;
            binding.progress.setVisibility(View.INVISIBLE);
            Snackbar.make(binding.getRoot(), result.isSuccessful() ? "聊天内容已删除并记录审计" : fallback(result.message(), "聊天内容删除失败"), Snackbar.LENGTH_LONG).show();
            if (result.isSuccessful()) load();
        });
    }

    private JsonObject communicationBody() {
        JsonObject body = new JsonObject();
        body.addProperty("channel_type", channelType());
        body.addProperty("channel_id", channelId());
        return body;
    }

    private void showPolicyDialog() {
        if (policyData.entrySet().isEmpty()) { loadPolicy(); return; }
        JsonObject stored = Jsons.object(policyData, "policy");
        boolean platform = AppAccess.from(this).session().role() == Role.PLATFORM;
        boolean editable = bool(policyData, "editable", false);
        LinearLayout content = new LinearLayout(this);
        content.setOrientation(LinearLayout.VERTICAL);
        int padding = dp(20);
        content.setPadding(padding, dp(8), padding, 0);
        TextView explanation = new TextView(this);
        explanation.setText("接管消息统一显示为“系统消息”，管理账号不会加入私聊、群聊或聊天室成员列表；真实操作者仅在审计记录中可查。");
        explanation.setTextColor(getColor(R.color.on_surface_variant));
        explanation.setPadding(0, 0, 0, dp(10));
        content.addView(explanation);
        LinkedHashMap<String, SwitchMaterial> fields = new LinkedHashMap<>();
        if (platform) {
            addSwitch(content, fields, stored, "platform_view_enabled", "允许平台查看下级通信");
            addSwitch(content, fields, stored, "platform_send_enabled", "允许平台发送系统接管消息");
            addSwitch(content, fields, stored, "platform_private_enabled", "平台可接管私聊");
            addSwitch(content, fields, stored, "platform_group_enabled", "平台可接管群聊与聊天室");
            addSwitch(content, fields, stored, "platform_service_enabled", "平台可接管客服会话");
        }
        addSwitch(content, fields, stored, "admin_view_enabled", "允许管理员查看下级通信");
        addSwitch(content, fields, stored, "admin_send_enabled", "允许管理员发送系统接管消息");
        addSwitch(content, fields, stored, "admin_private_enabled", "管理员可接管私聊");
        addSwitch(content, fields, stored, "admin_group_enabled", "管理员可接管群聊与聊天室");
        addSwitch(content, fields, stored, "admin_service_enabled", "管理员可接管客服会话");
        SwitchMaterial force = null;
        if (platform) {
            force = new SwitchMaterial(this);
            force.setText("强制下发，禁止下级修改");
            force.setChecked(Jsons.intValue(stored, "policy_locked_for_level", 0) > 0);
            force.setEnabled(editable);
            content.addView(force, switchParams());
        }
        for (SwitchMaterial toggle : fields.values()) toggle.setEnabled(editable);
        SwitchMaterial finalForce = force;
        new YiyunyingDialogBuilder(this)
            .setTitle("通信接管权限")
            .setView(content)
            .setNegativeButton("取消", null)
            .setPositiveButton(editable ? "保存" : "知道了", (dialog, which) -> {
                if (!editable) return;
                JsonObject body = new JsonObject();
                for (Map.Entry<String, SwitchMaterial> entry : fields.entrySet()) {
                    body.addProperty(entry.getKey(), entry.getValue().isChecked());
                }
                if (platform && finalForce != null) body.addProperty("force_descendants", finalForce.isChecked());
                savePolicy(body);
            })
            .show();
    }

    private void savePolicy(JsonObject body) {
        if (policySaveRequest != null) return;
        binding.progress.setVisibility(View.VISIBLE);
        policySaveRequest = AppAccess.from(this).repository().put(policyPath(), body, result -> {
            policySaveRequest = null;
            if (binding == null) return;
            binding.progress.setVisibility(View.INVISIBLE);
            if (!result.isSuccessful()) {
                Snackbar.make(binding.getRoot(), result.message().isEmpty() ? "接管权限保存失败" : result.message(), Snackbar.LENGTH_LONG).show();
                return;
            }
            renderPolicy(result.dataObject());
            Snackbar.make(binding.getRoot(), "通信接管权限已保存", Snackbar.LENGTH_SHORT).show();
            load();
        });
    }

    private void addSwitch(LinearLayout parent, LinkedHashMap<String, SwitchMaterial> fields,
                           JsonObject stored, String key, String label) {
        SwitchMaterial toggle = new SwitchMaterial(this);
        toggle.setText(label);
        toggle.setChecked(bool(stored, key, true));
        parent.addView(toggle, switchParams());
        fields.put(key, toggle);
    }

    private LinearLayout.LayoutParams switchParams() {
        return new LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, dp(48));
    }

    private String communicationPath() {
        long appId = getIntent().getLongExtra(EXTRA_APP_ID, 0);
        long userId = getIntent().getLongExtra(EXTRA_USER_ID, 0);
        String prefix = AppAccess.from(this).session().role() == Role.PLATFORM ? "/api/platform" : "/api/admin";
        return prefix + "/apps/" + appId + "/users/" + userId + "/communications";
    }

    private String policyPath() {
        String prefix = AppAccess.from(this).session().role() == Role.PLATFORM ? "/api/platform" : "/api/admin";
        return prefix + "/apps/" + getIntent().getLongExtra(EXTRA_APP_ID, 0) + "/communication-takeover-policy";
    }

    private String channelType() {
        String value = getIntent().getStringExtra(EXTRA_TYPE);
        if ("chat_room".equals(value) || "room".equals(value)) return "group";
        return value == null ? "" : value;
    }

    private long channelId() { return getIntent().getLongExtra(EXTRA_CHANNEL_ID, 0); }
    private long targetUserId() { return getIntent().getLongExtra(EXTRA_USER_ID, 0); }

    private String searchFilter() {
        int checked = binding.searchFilterGroup.getCheckedButtonId();
        if (checked == R.id.filterFile) return "file";
        if (checked == R.id.filterTag) return "tag";
        if (checked == R.id.filterSnapshot) return "snapshot";
        return "all";
    }

    private static boolean bool(JsonObject object, String key, boolean fallback) {
        if (object == null || !object.has(key) || object.get(key).isJsonNull()) return fallback;
        try { return object.get(key).getAsBoolean(); }
        catch (RuntimeException ignored) { return fallback; }
    }

    private static String fallback(String value, String fallback) {
        return value == null || value.trim().isEmpty() ? fallback : value;
    }

    private int dp(int value) {
        return Math.round(value * getResources().getDisplayMetrics().density);
    }

    @Override protected void onDestroy() {
        if (request != null) request.cancel();
        if (sendRequest != null) sendRequest.cancel();
        if (policyRequest != null) policyRequest.cancel();
        if (policySaveRequest != null) policySaveRequest.cancel();
        if (updateRequest != null) updateRequest.cancel();
        if (deleteRequest != null) deleteRequest.cancel();
        binding = null;
        super.onDestroy();
    }
}
