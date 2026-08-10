package xyz.jjmxg.yiyunying.ui.common;

import android.content.Context;
import android.text.InputType;
import android.view.View;
import android.widget.EditText;
import android.widget.LinearLayout;
import android.widget.TextView;

import com.google.android.material.button.MaterialButton;
import com.google.android.material.materialswitch.MaterialSwitch;
import com.google.android.material.textfield.TextInputEditText;
import com.google.android.material.textfield.TextInputLayout;
import com.google.gson.JsonArray;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;
import com.google.gson.JsonPrimitive;

import java.util.LinkedHashMap;
import java.util.ArrayList;
import java.util.List;
import java.util.Locale;
import java.util.Map;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.domain.module.FieldSpec;

public final class StructuredJsonInput extends LinearLayout {
    private static final String[] PERMISSION_KEYS = {
        "apps.manage", "users.manage", "documents.manage", "content.manage", "resources.manage",
        "forum.manage", "communication.manage", "cards.manage", "commerce.manage", "files.manage",
        "statistics.view", "downstream_users.access", "activities.manage",
    };
    private static final String[] PERMISSION_LABELS = {
        "管理应用", "管理用户", "管理文档", "管理公告与内容", "管理资源",
        "管理论坛", "管理消息与群聊", "管理卡密", "管理商城与订单", "管理远程文件",
        "查看统计", "允许下级用户使用", "管理活动",
    };
    private final FieldSpec field;
    private final Map<String, View> values = new LinkedHashMap<>();
    private final Map<String, String> objectAliases = new LinkedHashMap<>();
    private final List<TextInputLayout> optionInputs = new ArrayList<>();
    private LinearLayout optionContainer;
    private TextInputLayout linesLayout;
    private Mode mode;

    public StructuredJsonInput(Context context, FieldSpec field, JsonElement initial) {
        super(context);
        this.field = field;
        setOrientation(VERTICAL);
        setPadding(0, dp(4), 0, dp(4));
        TextView heading = new TextView(context);
        heading.setText(field.label() + (field.required() ? " *" : ""));
        heading.setTextAppearance(com.google.android.material.R.style.TextAppearance_Material3_TitleSmall);
        heading.setTextColor(context.getColor(R.color.on_surface));
        addView(heading, new LayoutParams(-1, -2));
        build(initial);
    }

    public JsonElement value() {
        switch (mode) {
            case PERMISSIONS: return permissionValue();
            case ENTITLEMENT: case GRANT: return fixedObjectValue();
            case NUMBER_ARRAY: return numberArrayValue();
            case STRING_ARRAY: return stringArrayValue();
            case OPTIONS: return optionsValue();
            case TARGETS: return targetArrayValue();
            case PRIZES: return prizeArrayValue();
            default: return objectValue();
        }
    }

    public boolean isEmpty() {
        JsonElement current = value();
        return (current.isJsonArray() && current.getAsJsonArray().isEmpty())
            || (current.isJsonObject() && current.getAsJsonObject().entrySet().isEmpty());
    }

    public void showError(String message) {
        if (linesLayout != null) linesLayout.setError(message);
        else if (!optionInputs.isEmpty()) optionInputs.get(0).setError(message);
    }

    private void build(JsonElement initial) {
        String key = field.key();
        if ("permissions".equals(key)) {
            mode = Mode.PERMISSIONS; buildPermissions(object(initial)); return;
        }
        if ("changes".equals(key)) {
            mode = Mode.ENTITLEMENT; buildEntitlement(object(initial)); return;
        }
        if ("grant".equals(key)) {
            mode = Mode.GRANT; buildGrant(object(initial)); return;
        }
        if ("options".equals(key)) {
            mode = Mode.OPTIONS; buildOptions(initial); return;
        }
        if ("option_ids".equals(key) || "category_ids".equals(key) || key.endsWith("_user_ids")
            || "tag_ids".equals(key) || "target_ids".equals(key)) {
            mode = Mode.NUMBER_ARRAY; buildLines("每行填写一个编号", initial, false); return;
        }
        if ("targets".equals(key) || "visibility_targets".equals(key) || "participation_targets".equals(key)) {
            mode = Mode.TARGETS; buildLines("每行一个目标，例如：层级,4 或 管理员,12", initialTargets(initial), true); return;
        }
        if ("prizes".equals(key)) {
            mode = Mode.PRIZES; buildLines("每行一个奖项：名称,奖励余额,权重,库存", initialPrizes(initial), true); return;
        }
        if (isStringArray(key)) {
            mode = Mode.STRING_ARRAY; buildLines(stringArrayHint(key), initial, true); return;
        }
        mode = Mode.OBJECT;
        registerAliases();
        buildLines("每行填写一个配置：配置名称=内容", initialObject(initial), true);
    }

    private void buildPermissions(JsonObject initial) {
        for (int index = 0; index < PERMISSION_KEYS.length; index++) {
            MaterialSwitch toggle = new MaterialSwitch(getContext());
            toggle.setText(PERMISSION_LABELS[index]);
            toggle.setMinHeight(dp(48));
            toggle.setChecked(!initial.has(PERMISSION_KEYS[index]) || bool(initial.get(PERMISSION_KEYS[index]), true));
            values.put(PERMISSION_KEYS[index], toggle);
            addView(toggle, new LayoutParams(-1, -2));
        }
    }

    private void buildEntitlement(JsonObject initial) {
        addField("membership_level", "会员等级", false, initial);
        addField("membership_status", "会员状态（有效/冻结/到期）", false, initial);
        addField("membership_expired_at", "会员到期时间", false, initial);
        addField("add_vip_days", "增加会员天数", true, initial);
        addField("app_quota", "应用总额度", true, initial);
        addField("app_quota_change", "应用额度增减", true, initial);
        addField("remote_document_quota", "远程文档总额度", true, initial);
        addField("remote_document_quota_change", "远程文档额度增减", true, initial);
        addField("balance", "余额总额", true, initial);
        addField("balance_change", "余额增减", true, initial);
        addField("access_start_time", "允许开始时间", false, initial);
        addField("access_end_time", "允许结束时间", false, initial);
        addField("allowed_weekdays", "允许使用星期", false, initial);
    }

    private void buildGrant(JsonObject initial) {
        addField("vip_days", "赠送会员天数", true, initial);
        addField("membership_level", "赠送会员等级", false, initial);
        addField("app_quota", "赠送应用额度", true, initial);
        addField("remote_document_quota", "赠送远程文档额度", true, initial);
    }

    private void buildOptions(JsonElement initial) {
        optionContainer = new LinearLayout(getContext());
        optionContainer.setOrientation(VERTICAL);
        addView(optionContainer, new LayoutParams(-1, -2));
        if (initial != null && initial.isJsonArray()) {
            for (JsonElement item : initial.getAsJsonArray()) {
                String value = "";
                if (item.isJsonPrimitive()) value = item.getAsString();
                else if (item.isJsonObject()) value = string(item.getAsJsonObject(), "option_text", string(item.getAsJsonObject(), "text", ""));
                addOption(value);
            }
        }
        while (optionInputs.size() < 2) addOption("");
        MaterialButton add = new MaterialButton(getContext());
        add.setText("添加选项");
        add.setIconResource(R.drawable.ic_add);
        add.setOnClickListener(view -> {
            if (optionInputs.size() < 50) addOption("");
        });
        LayoutParams params = new LayoutParams(-2, dp(48));
        params.topMargin = dp(8);
        addView(add, params);
    }

    private void addOption(String initial) {
        LinearLayout row = new LinearLayout(getContext());
        row.setOrientation(HORIZONTAL);
        row.setGravity(android.view.Gravity.CENTER_VERTICAL);
        TextInputLayout input = inputLayout("选项 " + (optionInputs.size() + 1), InputType.TYPE_CLASS_TEXT, false);
        input.getEditText().setText(initial == null ? "" : initial);
        optionInputs.add(input);
        row.addView(input, new LayoutParams(0, -2, 1f));
        MaterialButton remove = new MaterialButton(getContext());
        remove.setText("删除");
        ActionIconResolver.apply(remove, "删除这个投票选项", 0);
        remove.setOnClickListener(view -> {
            if (optionInputs.size() <= 2) {
                showError("投票至少需要两个选项");
                return;
            }
            optionInputs.remove(input);
            optionContainer.removeView(row);
        });
        LayoutParams removeParams = new LayoutParams(-2, dp(52));
        removeParams.leftMargin = dp(6);
        row.addView(remove, removeParams);
        LayoutParams rowParams = new LayoutParams(-1, -2);
        rowParams.topMargin = dp(6);
        optionContainer.addView(row, rowParams);
    }

    private void addField(String key, String label, boolean number, JsonObject initial) {
        TextInputLayout layout = inputLayout(label, number ? InputType.TYPE_CLASS_NUMBER | InputType.TYPE_NUMBER_FLAG_SIGNED : InputType.TYPE_CLASS_TEXT, false);
        if (initial.has(key) && !initial.get(key).isJsonNull()) layout.getEditText().setText(initial.get(key).getAsString());
        values.put(key, layout);
        LayoutParams params = new LayoutParams(-1, -2); params.topMargin = dp(8); addView(layout, params);
    }

    private void buildLines(String hint, JsonElement initial, boolean multiline) {
        String text;
        if (initial == null || initial.isJsonNull()) text = "";
        else if (initial.isJsonPrimitive()) text = initial.getAsString();
        else if (initial.isJsonArray()) {
            StringBuilder lines = new StringBuilder();
            for (JsonElement item : initial.getAsJsonArray()) {
                if (lines.length() > 0) lines.append('\n');
                lines.append(item.isJsonPrimitive() ? item.getAsString() : DisplayText.value(item));
            }
            text = lines.toString();
        } else text = initialObject(initial);
        buildLines(hint, text, multiline);
    }

    private void buildLines(String hint, String text, boolean multiline) {
        linesLayout = inputLayout(hint, InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_FLAG_MULTI_LINE, multiline);
        linesLayout.getEditText().setText(text == null ? "" : text);
        LayoutParams params = new LayoutParams(-1, -2); params.topMargin = dp(8); addView(linesLayout, params);
    }

    private TextInputLayout inputLayout(String hint, int inputType, boolean multiline) {
        TextInputLayout layout = new TextInputLayout(getContext(), null, com.google.android.material.R.attr.textInputOutlinedStyle);
        layout.setHint(hint);
        TextInputEditText input = new TextInputEditText(layout.getContext());
        input.setInputType(inputType);
        input.setMinLines(multiline ? 3 : 1);
        input.setMaxLines(multiline ? 10 : 2);
        SafeTextInput.attach(layout, input);
        return layout;
    }

    private JsonObject permissionValue() {
        JsonObject result = new JsonObject();
        for (Map.Entry<String, View> entry : values.entrySet()) {
            result.addProperty(entry.getKey(), ((MaterialSwitch) entry.getValue()).isChecked());
        }
        return result;
    }

    private JsonObject fixedObjectValue() {
        JsonObject result = new JsonObject();
        for (Map.Entry<String, View> entry : values.entrySet()) {
            String text = text((TextInputLayout) entry.getValue());
            if (text.isEmpty()) continue;
            if (isNumericKey(entry.getKey())) {
                try { result.addProperty(entry.getKey(), Long.parseLong(text)); }
                catch (NumberFormatException exception) { throw new IllegalArgumentException("请输入正确的数字"); }
            } else {
                result.addProperty(entry.getKey(), normalizeChoice(entry.getKey(), text));
            }
        }
        return result;
    }

    private JsonArray numberArrayValue() {
        JsonArray result = new JsonArray();
        for (String line : lines().split("[,，\\s]+")) {
            if (line.trim().isEmpty()) continue;
            try { result.add(Long.parseLong(line.trim())); }
            catch (NumberFormatException exception) { throw new IllegalArgumentException("编号必须是数字"); }
        }
        return result;
    }

    private JsonArray stringArrayValue() {
        JsonArray result = new JsonArray();
        for (String line : lines().split("[\\r\\n]+")) {
            String value = line.trim();
            if (!value.isEmpty()) result.add(value);
        }
        return result;
    }

    private JsonArray optionsValue() {
        JsonArray result = new JsonArray();
        for (TextInputLayout input : optionInputs) {
            String value = text(input);
            if (!value.isEmpty()) result.add(value);
        }
        if (!result.isEmpty() && result.size() < 2) throw new IllegalArgumentException("投票至少需要两个选项");
        return result;
    }

    private JsonArray targetArrayValue() {
        JsonArray result = new JsonArray();
        for (String line : lines().split("[\\r\\n]+")) {
            String value = line.trim();
            if (value.isEmpty()) continue;
            String[] parts = value.split("[,，:：]", -1);
            if (parts.length < 2) throw new IllegalArgumentException("目标需填写类型和编号或层级");
            String type = targetType(parts[0].trim());
            long target;
            try { target = Long.parseLong(parts[1].trim()); }
            catch (NumberFormatException exception) { throw new IllegalArgumentException("目标编号或层级必须是数字"); }
            JsonObject item = new JsonObject();
            item.addProperty("type", type);
            if ("level".equals(type)) item.addProperty("level", target);
            else item.addProperty("id", target);
            if ("actor".equals(type) && parts.length > 2) item.addProperty("actor_type", actorType(parts[2].trim()));
            result.add(item);
        }
        return result;
    }

    private JsonArray prizeArrayValue() {
        JsonArray result = new JsonArray();
        for (String line : lines().split("[\\r\\n]+")) {
            String value = line.trim();
            if (value.isEmpty()) continue;
            String[] parts = value.split("[,，]", -1);
            if (parts.length < 2 || parts[0].trim().isEmpty()) throw new IllegalArgumentException("奖项至少需要名称和奖励余额");
            JsonObject prize = new JsonObject();
            prize.addProperty("name", parts[0].trim());
            try {
                prize.addProperty("reward_balance", Long.parseLong(parts[1].trim()));
                prize.addProperty("weight", parts.length > 2 && !parts[2].trim().isEmpty() ? Long.parseLong(parts[2].trim()) : 1);
                prize.addProperty("stock", parts.length > 3 && !parts[3].trim().isEmpty() ? Long.parseLong(parts[3].trim()) : 1);
            } catch (NumberFormatException exception) { throw new IllegalArgumentException("奖项余额、权重和库存必须是数字"); }
            result.add(prize);
        }
        return result;
    }

    private JsonObject objectValue() {
        JsonObject result = new JsonObject();
        for (String line : lines().split("[\\r\\n]+")) {
            String value = line.trim();
            if (value.isEmpty()) continue;
            int separator = Math.max(value.indexOf('='), value.indexOf('＝'));
            if (separator <= 0) throw new IllegalArgumentException("配置项应使用“名称=内容”格式");
            String label = value.substring(0, separator).trim();
            String key = objectAliases.getOrDefault(label, label);
            result.add(key, primitive(value.substring(separator + 1).trim()));
        }
        return result;
    }

    private void registerAliases() {
        alias("注册开关", "registration_enabled"); alias("登录开关", "login_enabled");
        alias("默认接收陌生人消息", "accept_stranger_messages_default");
        alias("聊天轮询间隔毫秒", "chat_poll_interval_ms"); alias("默认聊天轮询间隔毫秒", "default_chat_poll_interval_ms");
        alias("强制聊天轮询间隔", "force_chat_poll_interval"); alias("最小聊天轮询间隔毫秒", "min_chat_poll_interval_ms");
        alias("最大聊天轮询间隔毫秒", "max_chat_poll_interval_ms");
        alias("管理员注册赠送会员天数", "admin_free_trial_days"); alias("管理员注册赠送应用额度", "admin_free_app_quota");
        alias("管理员注册赠送文档额度", "admin_free_remote_document_quota"); alias("管理员注册赠送余额", "admin_free_balance");
        alias("授权平台注册赠送会员天数", "operator_free_trial_days"); alias("授权平台注册赠送管理员额度", "operator_free_admin_quota");
        alias("授权平台注册赠送余额", "operator_free_balance"); alias("商户编号", "merchant_id");
        alias("应用编号", "app_id"); alias("应用密钥", "app_secret"); alias("接口密钥", "secret");
        alias("回调地址", "callback_url"); alias("网关地址", "gateway_url");
    }

    private void alias(String label, String key) { objectAliases.put(label, key); objectAliases.put(key, key); }

    private String initialObject(JsonElement initial) {
        JsonObject object = object(initial);
        StringBuilder result = new StringBuilder();
        for (Map.Entry<String, JsonElement> entry : object.entrySet()) {
            String label = aliasLabel(entry.getKey());
            objectAliases.put(label, entry.getKey());
            if (result.length() > 0) result.append('\n');
            result.append(label).append('=').append(DisplayText.value(entry.getValue()));
        }
        return result.toString();
    }

    private String initialTargets(JsonElement initial) {
        if (initial == null || !initial.isJsonArray()) return "";
        StringBuilder result = new StringBuilder();
        for (JsonElement element : initial.getAsJsonArray()) {
            if (!element.isJsonObject()) continue;
            JsonObject item = element.getAsJsonObject();
            String type = string(item, "type", string(item, "target_type", "level"));
            String label = targetLabel(type);
            long id = "level".equals(type) ? longValue(item, "level", longValue(item, "target_level", 0))
                : longValue(item, "id", longValue(item, "target_id", 0));
            if (result.length() > 0) result.append('\n');
            result.append(label).append(',').append(id);
            if ("actor".equals(type)) result.append(',').append(actorLabel(string(item, "actor_type", "user")));
        }
        return result.toString();
    }

    private String initialPrizes(JsonElement initial) {
        if (initial == null || !initial.isJsonArray()) return "";
        StringBuilder result = new StringBuilder();
        for (JsonElement element : initial.getAsJsonArray()) {
            if (!element.isJsonObject()) continue;
            JsonObject item = element.getAsJsonObject();
            if (result.length() > 0) result.append('\n');
            result.append(string(item, "name", "奖项")).append(',')
                .append(longValue(item, "reward_balance", 0)).append(',')
                .append(longValue(item, "weight", 1)).append(',').append(longValue(item, "stock", 1));
        }
        return result.toString();
    }

    private static JsonObject object(JsonElement initial) {
        return initial != null && initial.isJsonObject() ? initial.getAsJsonObject() : new JsonObject();
    }

    private String lines() { return text(linesLayout); }
    private static String text(TextInputLayout layout) {
        EditText edit = layout == null ? null : layout.getEditText();
        return edit == null || edit.getText() == null ? "" : edit.getText().toString().trim();
    }
    private static boolean isStringArray(String key) {
        return "images".equals(key) || "allowlist".equals(key)
            || "audience".equals(key) || "fields".equals(key);
    }
    private static String stringArrayHint(String key) {
        if ("images".equals(key)) return "每行填写一个图片地址";
        if ("allowlist".equals(key)) return "每行填写一个允许访问的 IP";
        if ("audience".equals(key)) return "每行填写一个可见对象";
        return "每行填写一项";
    }
    private static boolean isNumericKey(String key) {
        return key.endsWith("_days") || key.endsWith("_quota") || key.endsWith("_change") || "balance".equals(key);
    }
    private static String normalizeChoice(String key, String value) {
        if ("membership_status".equals(key)) {
            if (value.contains("冻结")) return "frozen"; if (value.contains("到期")) return "expired"; if (value.contains("有效")) return "active";
        }
        if ("membership_level".equals(key)) {
            String lower = value.toLowerCase(Locale.ROOT);
            if (lower.contains("svip")) return "svip"; if (lower.contains("vip") || value.contains("会员")) return "vip"; if (value.contains("试用")) return "trial";
        }
        return value;
    }
    private static JsonElement primitive(String value) {
        if ("true".equalsIgnoreCase(value) || "是".equals(value) || "开启".equals(value)) return new JsonPrimitive(true);
        if ("false".equalsIgnoreCase(value) || "否".equals(value) || "关闭".equals(value)) return new JsonPrimitive(false);
        try { return value.contains(".") ? new JsonPrimitive(Double.parseDouble(value)) : new JsonPrimitive(Long.parseLong(value)); }
        catch (NumberFormatException ignored) { return new JsonPrimitive(value); }
    }
    private String aliasLabel(String key) {
        for (Map.Entry<String, String> entry : objectAliases.entrySet()) if (entry.getValue().equals(key) && !entry.getKey().equals(key)) return entry.getKey();
        String label = DisplayText.label(key); return label.isEmpty() ? "配置项" : label;
    }
    private static String targetType(String label) {
        String value = label.toLowerCase(Locale.ROOT);
        if (value.contains("层级") || value.equals("level")) return "level";
        if (value.contains("平台") || value.equals("platform")) return "platform";
        if (value.contains("管理员") || value.equals("admin")) return "admin";
        if (value.contains("应用") || value.equals("app")) return "app";
        if (value.contains("账号") || value.equals("actor")) return "actor";
        throw new IllegalArgumentException("目标类型仅支持层级、平台、管理员、应用或账号");
    }
    private static String targetLabel(String type) {
        if ("platform".equals(type)) return "平台"; if ("admin".equals(type)) return "管理员";
        if ("app".equals(type)) return "应用"; if ("actor".equals(type)) return "账号"; return "层级";
    }
    private static String actorType(String label) {
        if (label.contains("平台") || "platform".equalsIgnoreCase(label)) return "platform";
        if (label.contains("管理员") || "admin".equalsIgnoreCase(label)) return "admin"; return "user";
    }
    private static String actorLabel(String type) {
        if ("platform".equals(type)) return "平台"; if ("admin".equals(type)) return "管理员"; return "用户";
    }
    private static boolean bool(JsonElement value, boolean fallback) {
        try { return value == null || value.isJsonNull() ? fallback : value.getAsBoolean(); }
        catch (RuntimeException ignored) { return fallback; }
    }
    private static String string(JsonObject object, String key, String fallback) {
        try { return object.has(key) && !object.get(key).isJsonNull() ? object.get(key).getAsString() : fallback; }
        catch (RuntimeException ignored) { return fallback; }
    }
    private static long longValue(JsonObject object, String key, long fallback) {
        try { return object.has(key) && !object.get(key).isJsonNull() ? object.get(key).getAsLong() : fallback; }
        catch (RuntimeException ignored) { return fallback; }
    }
    private int dp(int value) { return Math.round(value * getResources().getDisplayMetrics().density); }
    private enum Mode { PERMISSIONS, ENTITLEMENT, GRANT, NUMBER_ARRAY, STRING_ARRAY, OPTIONS, TARGETS, PRIZES, OBJECT }
}
