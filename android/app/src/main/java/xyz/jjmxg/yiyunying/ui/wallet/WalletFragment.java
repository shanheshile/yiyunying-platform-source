package xyz.jjmxg.yiyunying.ui.wallet;

import android.content.ClipData;
import android.content.ClipboardManager;
import android.content.Context;
import android.os.Bundle;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.widget.GridLayout;

import androidx.annotation.NonNull;
import androidx.annotation.Nullable;

import com.google.android.material.button.MaterialButton;
import xyz.jjmxg.yiyunying.ui.common.YiyunyingDialogBuilder;
import com.google.gson.JsonArray;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;

import java.util.LinkedHashMap;
import java.util.Map;
import java.util.UUID;

import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.databinding.FragmentWalletBinding;
import xyz.jjmxg.yiyunying.databinding.ItemKeyValueBinding;
import xyz.jjmxg.yiyunying.databinding.ItemMetricBinding;
import xyz.jjmxg.yiyunying.domain.Role;
import xyz.jjmxg.yiyunying.domain.module.ActionSpec;
import xyz.jjmxg.yiyunying.domain.module.FieldSpec;
import xyz.jjmxg.yiyunying.domain.module.FieldType;
import xyz.jjmxg.yiyunying.core.RuntimeLanguage;
import xyz.jjmxg.yiyunying.ui.common.BaseFragment;
import xyz.jjmxg.yiyunying.ui.common.DisplayText;
import xyz.jjmxg.yiyunying.ui.common.DynamicFormDialog;

public final class WalletFragment extends BaseFragment {
    private FragmentWalletBinding binding;

    public static WalletFragment newInstance(String ignored) { return new WalletFragment(); }

    @Nullable
    @Override public View onCreateView(@NonNull LayoutInflater inflater, @Nullable ViewGroup container, @Nullable Bundle state) {
        binding = FragmentWalletBinding.inflate(inflater, container, false);
        host().setPageTitle(tr(app().session().role() == Role.USER ? "资产与签到" : "我的权益"));
        binding.swipeRefresh.setOnRefreshListener(this::load);
        buildActions();
        load();
        return binding.getRoot();
    }

    private void buildActions() {
        binding.actionContainer.removeAllViews();
        if (app().session().role() == Role.USER) {
            addButton("资金来往明细", view -> WalletLedgerActivity.open(requireContext()));
            addButton("每日签到", view -> simplePost("/api/user/sign", new JsonObject(), false));
            addButton("兑换卡密", view -> form(ActionSpec.builder(tr("兑换卡密"), "POST", "/api/user/cards/redeem")
                .fields(FieldSpec.required("card_code", tr("卡密码"))).idempotent().build()));
            addButton("余额转账", view -> form(ActionSpec.builder(tr("余额转账"), "POST", "/api/user/wallet/transfer")
                .fields(
                    FieldSpec.typed("to_user_id", tr("收款用户 ID"), FieldType.INTEGER, true),
                    FieldSpec.typed("amount", tr("余额"), FieldType.DECIMAL, true)
                ).idempotent().build()));
            addButton("购买笔记额度", view -> purchase("document_credit", "购买笔记额度", "购买份数"));
            addButton("购买会员", view -> purchase("vip_days", "购买会员", "购买天数"));
            addButton("查看我的邀请码", view -> showInviteCode());
        } else {
            addButton("余额兑换", view -> form(ActionSpec.builder(tr("余额兑换"), "POST", "/api/admin/exchanges")
                .fields(
                    FieldSpec.typed("product_id", tr("商品 ID"), FieldType.INTEGER, true),
                    FieldSpec.typed("quantity", tr("数量"), FieldType.INTEGER, true).withDefault("1")
                ).idempotent().build()));
            addButton("提交购买申请", view -> form(ActionSpec.builder(tr("购买额度或会员"), "POST", "/api/admin/purchase-orders")
                .fields(
                    FieldSpec.required("purchase_type", tr("购买类型")),
                    FieldSpec.typed("quantity", tr("数量"), FieldType.INTEGER, true),
                    FieldSpec.typed("amount", tr("金额"), FieldType.DECIMAL, false),
                    FieldSpec.of("remark", tr("备注"))
                ).build()));
        }
    }

    private void addButton(String text, View.OnClickListener listener) {
        MaterialButton button = new MaterialButton(requireContext(), null, com.google.android.material.R.attr.materialButtonOutlinedStyle);
        button.setText(tr(text));
        button.setMinHeight(dp(50));
        button.setOnClickListener(listener);
        android.widget.LinearLayout.LayoutParams params = new android.widget.LinearLayout.LayoutParams(-1, -2);
        params.topMargin = dp(8);
        binding.actionContainer.addView(button, params);
    }

    private void load() {
        String path = app().session().role() == Role.USER ? "/api/user/wallet" : "/api/admin/entitlement";
        binding.progress.setVisibility(View.VISIBLE);
        track(app().repository().get(path, new LinkedHashMap<>(), result -> {
            if (binding == null) return;
            binding.progress.setVisibility(View.GONE);
            binding.swipeRefresh.setRefreshing(false);
            if (handleFailure(result, binding.getRoot())) return;
            render(result.dataObject());
            loadLogs();
        }));
    }

    private void render(JsonObject data) {
        binding.metricsGrid.removeAllViews();
        if (app().session().role() == Role.USER) {
            JsonObject wallet = Jsons.object(data, "wallet");
            addMetric("余额", wallet.get("balance"));
            addMetric("笔记额度", wallet.get("document_credit"));
            addMetric("经验", wallet.get("experience"));
            if (wallet.has("activity_credit") && Jsons.longValue(wallet, "activity_credit") > 0) {
                addMetric("活动币", wallet.get("activity_credit"));
            }
            addMetric("等级", wallet.get("level_code"));
            addMetric("会员到期", wallet.get("vip_expired_at"));
            setDynamic(binding.statusText, tr("资产更新时间") + " " + Jsons.string(wallet, "updated_at"));
        } else {
            JsonObject membership = Jsons.object(data, "membership");
            JsonObject quotas = Jsons.object(data, "quotas");
            JsonObject apps = Jsons.object(quotas, "apps");
            JsonObject remote = Jsons.object(quotas, "remote_documents");
            JsonElement balance = quotas.has("balance") ? quotas.get("balance") : quotas.get("integral");
            addMetric("余额", balance);
            addMetric("会员等级", membership.get("level"));
            addMetric("会员到期", membership.get("expired_at"));
            addMetric("应用额度", value(apps, "used", "limit"));
            addMetric("远程文档额度", value(remote, "used", "limit"));
            JsonObject downstream = Jsons.object(data, "downstream");
            addMetric("下游用户", downstream.get("users"));
            JsonObject access = Jsons.object(data, "access");
            boolean changed = app().session().updateAdminAccess(Jsons.string(access, "mode"), Jsons.string(access, "reason"));
            setDynamic(binding.statusText, tr("访问模式") + " " + Jsons.string(access, "mode")
                + " · " + Jsons.string(access, "reason"));
            if (changed) host().onAdminAccessStateChanged();
        }
    }

    private JsonElement value(JsonObject object, String used, String limit) {
        return new com.google.gson.JsonPrimitive(Jsons.string(object, used) + " / " + Jsons.string(object, limit));
    }

    private void addMetric(String label, JsonElement value) {
        ItemMetricBinding item = ItemMetricBinding.inflate(getLayoutInflater(), binding.metricsGrid, false);
        item.label.setText(tr(label));
        setDynamic(item.value, DisplayText.value(value));
        GridLayout.LayoutParams params = new GridLayout.LayoutParams();
        params.width = 0;
        params.height = GridLayout.LayoutParams.WRAP_CONTENT;
        params.columnSpec = GridLayout.spec(GridLayout.UNDEFINED, 1f);
        params.setMargins(dp(3), dp(3), dp(3), dp(3));
        binding.metricsGrid.addView(item.getRoot(), params);
    }

    private void loadLogs() {
        boolean userWallet = app().session().role() == Role.USER;
        String path = userWallet ? "/api/user/wallet/logs" : "/api/admin/balance-logs";
        Map<String, String> query = new LinkedHashMap<>();
        query.put("page", "1");
        query.put("limit", "8");
        track(app().repository().get(path, query, result -> {
            if (binding == null || !result.isSuccessful()) return;
            binding.logContainer.removeAllViews();
            JsonArray items = result.items();
            for (JsonElement element : items) {
                if (!element.isJsonObject()) continue;
                JsonObject item = element.getAsJsonObject();
                ItemKeyValueBinding row = ItemKeyValueBinding.inflate(getLayoutInflater(), binding.logContainer, false);
                String key = userWallet ? Jsons.string(item, "scene_name") : Jsons.string(item, "scene");
                if (key.isEmpty()) key = Jsons.string(item, "module") + " · " + Jsons.string(item, "action");
                row.key.setText(tr(DisplayText.eventLabel(key)));
                String value = userWallet
                    ? Jsons.string(item, "signed_amount") + " · " + Jsons.string(item, "created_at")
                    : Jsons.string(item, "change_value");
                if (value.isEmpty()) value = Jsons.string(item, "created_at");
                setDynamic(row.value, value);
                binding.logContainer.addView(row.getRoot());
            }
        }));
    }

    private void form(ActionSpec action) {
        DynamicFormDialog.show(requireContext(), action, null, body -> simplePost(action.pathTemplate(), body, action.idempotent()));
    }

    private void purchase(String productType, String title, String quantityLabel) {
        ActionSpec action = ActionSpec.builder(tr(title), "POST", "/api/user/wallet/purchases")
            .fields(FieldSpec.typed("quantity", tr(quantityLabel), FieldType.INTEGER, true).withDefault("1"))
            .idempotent().build();
        DynamicFormDialog.show(requireContext(), action, null, body -> {
            body.addProperty("product_type", productType);
            simplePost(action.pathTemplate(), body, true);
        });
    }

    private void simplePost(String path, JsonObject body, boolean idempotent) {
        binding.progress.setVisibility(View.VISIBLE);
        String key = idempotent ? UUID.randomUUID().toString() : "";
        track(app().repository().post(path, body, key, result -> {
            if (binding == null) return;
            binding.progress.setVisibility(View.GONE);
            if (handleFailure(result, binding.getRoot())) return;
            message(binding.getRoot(), result.message().isEmpty() ? tr("操作成功") : result.message());
            load();
        }));
    }

    private void showInviteCode() {
        binding.progress.setVisibility(View.VISIBLE);
        track(app().repository().post("/api/user/invite-code", new JsonObject(), result -> {
            if (binding == null) return;
            binding.progress.setVisibility(View.GONE);
            if (handleFailure(result, binding.getRoot())) return;
            JsonObject invite = Jsons.object(result.dataObject(), "invite");
            String code = Jsons.string(invite, "invite_code");
            if (code.isEmpty()) {
                message(binding.getRoot(), tr("服务器没有返回邀请码"));
                return;
            }
            String usage = tr("邀请码：") + code
                + "\n" + tr("已使用：") + Jsons.intValue(invite, "used_count", 0)
                + (Jsons.intValue(invite, "max_use", 0) > 0
                    ? " / " + Jsons.intValue(invite, "max_use", 0) : " / " + tr("不限"))
                + "\n\n" + tr("新用户注册时填写该邀请码即可建立邀请关系。");
            new YiyunyingDialogBuilder(requireContext())
                .setTitle(tr("我的固定邀请码"))
                .setMessage(usage)
                .setNeutralButton(tr("复制邀请码"), (dialog, which) -> copyInvite(code))
                .setPositiveButton(tr("完成"), null)
                .show();
        }));
    }

    private void copyInvite(String code) {
        ClipboardManager manager = (ClipboardManager) requireContext().getSystemService(Context.CLIPBOARD_SERVICE);
        if (manager != null) manager.setPrimaryClip(ClipData.newPlainText("邀请码", code));
        message(binding.getRoot(), tr("邀请码已复制"));
    }

    private String tr(String value) {
        CharSequence translated = RuntimeLanguage.translate(requireContext(), value);
        return translated == null ? value : translated.toString();
    }

    private void setDynamic(android.widget.TextView view, CharSequence value) {
        RuntimeLanguage.setDynamicText(view, value);
    }

    private int dp(int value) { return Math.round(value * getResources().getDisplayMetrics().density); }
    @Override public void onDestroyView() { binding = null; super.onDestroyView(); }
}
