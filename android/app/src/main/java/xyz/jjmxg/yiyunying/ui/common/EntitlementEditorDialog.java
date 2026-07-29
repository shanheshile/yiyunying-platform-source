package xyz.jjmxg.yiyunying.ui.common;

import android.content.Context;
import android.text.InputType;
import android.view.View;
import android.widget.LinearLayout;
import android.widget.TextView;

import androidx.appcompat.app.AlertDialog;

import xyz.jjmxg.yiyunying.ui.common.YiyunyingDialogBuilder;
import com.google.android.material.textfield.MaterialAutoCompleteTextView;
import com.google.android.material.textfield.TextInputEditText;
import com.google.android.material.textfield.TextInputLayout;
import com.google.gson.JsonObject;

import java.util.LinkedHashMap;
import java.util.Map;

import xyz.jjmxg.yiyunying.R;

public final class EntitlementEditorDialog {
    public enum TargetKind { AUTHORIZED_PLATFORM, ADMIN, USER }
    public interface Listener { void onSubmit(JsonObject body); }

    private EntitlementEditorDialog() { }

    public static void show(Context context, TargetKind kind, int selectedCount, Listener listener) {
        LinearLayout content = new LinearLayout(context);
        content.setOrientation(LinearLayout.VERTICAL);
        int padding = dp(context, 20);
        content.setPadding(padding, dp(context, 8), padding, dp(context, 6));

        Map<String, String> types = types(kind);
        TextInputLayout typeLayout = dropdown(context, "调整内容", types.keySet().toArray(new String[0]));
        TextInputLayout operationLayout = dropdown(context, "操作方式", new String[]{"增加", "减少", "设为"});
        TextInputLayout amountLayout = input(context, "数量", InputType.TYPE_CLASS_NUMBER | InputType.TYPE_NUMBER_FLAG_DECIMAL);
        TextInputLayout unitLayout = dropdown(context, "时间单位", new String[]{"秒", "分", "时", "天", "周", "月", "季", "年"});
        TextInputLayout levelLayout = dropdown(context, "会员等级", new String[]{"VIP", "SVIP", "试用会员"});
        TextInputLayout remarkLayout = input(context, "调整说明", InputType.TYPE_CLASS_TEXT);
        content.addView(typeLayout, params(context)); content.addView(operationLayout, params(context));
        content.addView(amountLayout, params(context)); content.addView(unitLayout, params(context));
        content.addView(levelLayout, params(context)); content.addView(remarkLayout, params(context));

        String firstType = types.keySet().iterator().next();
        auto(typeLayout).setText(firstType, false);
        auto(operationLayout).setText("增加", false);
        auto(unitLayout).setText("天", false);
        auto(levelLayout).setText("VIP", false);
        updateVisibility(types.get(firstType), amountLayout, unitLayout, levelLayout);
        auto(typeLayout).setOnItemClickListener((parent, view, position, id) ->
            updateVisibility(types.get(parent.getItemAtPosition(position).toString()), amountLayout, unitLayout, levelLayout));

        String title = selectedCount > 1 ? "批量调整 " + selectedCount + " 个账号" : "调整账号权益";
        AlertDialog dialog = new YiyunyingDialogBuilder(context)
            .setTitle(title)
            .setView(content)
            .setNegativeButton("取消", null)
            .setPositiveButton("确定", null)
            .create();
        dialog.setOnShowListener(ignored -> dialog.getButton(AlertDialog.BUTTON_POSITIVE).setOnClickListener(view -> {
            amountLayout.setError(null);
            String amountText = text(amountLayout);
            if (amountText.isEmpty()) {
                amountLayout.setError("请填写数量");
                return;
            }
            double amount;
            try { amount = Double.parseDouble(amountText); }
            catch (NumberFormatException exception) { amountLayout.setError("请输入正确的数字"); return; }
            if (amount < 0) { amountLayout.setError("数量不能小于 0，请选择减少操作"); return; }
            String typeLabel = auto(typeLayout).getText().toString();
            String type = types.get(typeLabel);
            if (type == null) return;
            JsonObject body = new JsonObject();
            body.addProperty("entitlement_type", type);
            body.addProperty("operation", operation(auto(operationLayout).getText().toString()));
            if (isIntegerType(type)) body.addProperty("amount", (long) amount);
            else body.addProperty("amount", amount);
            if ("vip".equals(type)) {
                body.addProperty("duration_unit", unit(auto(unitLayout).getText().toString()));
                body.addProperty("membership_level", level(auto(levelLayout).getText().toString()));
            }
            String remark = text(remarkLayout);
            if (!remark.isEmpty()) body.addProperty("remark", remark);
            dialog.dismiss();
            listener.onSubmit(body);
        }));
        dialog.show();
    }

    private static Map<String, String> types(TargetKind kind) {
        Map<String, String> result = new LinkedHashMap<>();
        result.put("VIP 时间", "vip"); result.put("余额", "balance");
        if (kind == TargetKind.AUTHORIZED_PLATFORM) {
            result.put("管理员额度", "admin_quota");
            result.put("下级注册赠送会员天数", "gift_membership");
            result.put("下级注册赠送应用额度", "gift_app_quota");
            result.put("下级注册赠送文档额度", "gift_document_quota");
            result.put("下级注册赠送余额", "gift_balance");
        } else if (kind == TargetKind.ADMIN) {
            result.put("远程文档额度", "document_quota");
            result.put("应用额度", "app_quota");
        } else {
            result.put("笔记额度", "document_credit");
            result.put("活动币", "activity_credit");
            result.put("经验", "experience");
        }
        return result;
    }

    private static void updateVisibility(String type, TextInputLayout amount, TextInputLayout unit, TextInputLayout level) {
        boolean vip = "vip".equals(type);
        unit.setVisibility(vip ? View.VISIBLE : View.GONE);
        level.setVisibility(vip ? View.VISIBLE : View.GONE);
        if (type == null) amount.setHint("数量");
        else if (vip) amount.setHint("时间数量");
        else if (type.contains("balance")) amount.setHint("余额数量");
        else if (type.contains("document")) amount.setHint("文档数量");
        else amount.setHint("额度数量");
    }

    private static TextInputLayout dropdown(Context context, String hint, String[] values) {
        TextInputLayout layout = new TextInputLayout(context, null, com.google.android.material.R.attr.textInputOutlinedExposedDropdownMenuStyle);
        layout.setHint(hint);
        layout.setEndIconMode(TextInputLayout.END_ICON_DROPDOWN_MENU);
        MaterialAutoCompleteTextView input = new MaterialAutoCompleteTextView(layout.getContext());
        input.setSimpleItems(values);
        input.setInputType(InputType.TYPE_NULL);
        SafeTextInput.attach(layout, input);
        return layout;
    }

    private static TextInputLayout input(Context context, String hint, int inputType) {
        TextInputLayout layout = new TextInputLayout(context, null, com.google.android.material.R.attr.textInputOutlinedStyle);
        layout.setHint(hint);
        TextInputEditText input = new TextInputEditText(layout.getContext());
        input.setInputType(inputType);
        SafeTextInput.attach(layout, input);
        return layout;
    }

    private static MaterialAutoCompleteTextView auto(TextInputLayout layout) {
        return (MaterialAutoCompleteTextView) layout.getEditText();
    }

    private static String text(TextInputLayout layout) {
        return layout.getEditText() == null || layout.getEditText().getText() == null
            ? "" : layout.getEditText().getText().toString().trim();
    }

    private static String operation(String value) {
        if (value.contains("减少")) return "decrease";
        if (value.contains("设为")) return "set";
        return "increase";
    }
    private static String unit(String value) {
        if ("秒".equals(value)) return "second"; if ("分".equals(value)) return "minute";
        if ("时".equals(value)) return "hour"; if ("周".equals(value)) return "week";
        if ("月".equals(value)) return "month"; if ("季".equals(value)) return "quarter";
        if ("年".equals(value)) return "year"; return "day";
    }
    private static String level(String value) {
        if (value.toUpperCase().contains("SVIP")) return "svip";
        if (value.contains("试用")) return "trial"; return "vip";
    }
    private static boolean isIntegerType(String type) { return !"balance".equals(type); }
    private static LinearLayout.LayoutParams params(Context context) {
        LinearLayout.LayoutParams params = new LinearLayout.LayoutParams(-1, -2); params.topMargin = dp(context, 10); return params;
    }
    private static int dp(Context context, int value) {
        return Math.round(value * context.getResources().getDisplayMetrics().density);
    }
}
