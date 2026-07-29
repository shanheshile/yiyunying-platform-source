package xyz.jjmxg.yiyunying.ui.settings;

import xyz.jjmxg.yiyunying.ui.common.SafeTextInput;

import android.os.Bundle;
import android.text.InputType;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.widget.EditText;
import android.widget.LinearLayout;

import androidx.annotation.NonNull;
import androidx.annotation.Nullable;

import com.google.android.material.materialswitch.MaterialSwitch;
import com.google.android.material.textfield.TextInputEditText;
import com.google.android.material.textfield.TextInputLayout;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;
import com.google.gson.JsonParser;

import java.util.LinkedHashMap;
import java.util.Map;

import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.databinding.FragmentSettingsBinding;
import xyz.jjmxg.yiyunying.domain.Role;
import xyz.jjmxg.yiyunying.domain.module.ModuleSpec;
import xyz.jjmxg.yiyunying.domain.module.PathResolver;
import xyz.jjmxg.yiyunying.ui.common.BaseFragment;
import xyz.jjmxg.yiyunying.ui.common.DisplayText;
import xyz.jjmxg.yiyunying.ui.common.StructuredJsonInput;
import xyz.jjmxg.yiyunying.domain.module.FieldSpec;
import xyz.jjmxg.yiyunying.domain.module.FieldType;

public final class SettingsFragment extends BaseFragment {
    private static final String ARG_MODULE_ID = "module_id";
    private FragmentSettingsBinding binding;
    private ModuleSpec spec;
    private final Map<String, View> settingViews = new LinkedHashMap<>();
    private final Map<String, JsonElement> settingTypes = new LinkedHashMap<>();
    private final Map<String, View> featureViews = new LinkedHashMap<>();

    public static SettingsFragment newInstance(String moduleId) {
        SettingsFragment fragment = new SettingsFragment();
        Bundle args = new Bundle();
        args.putString(ARG_MODULE_ID, moduleId);
        fragment.setArguments(args);
        return fragment;
    }

    @Override public void onCreate(@Nullable Bundle state) {
        super.onCreate(state);
        spec = app().modules().find(app().session().role(), requireArguments().getString(ARG_MODULE_ID, "settings"));
    }

    @Nullable
    @Override public View onCreateView(@NonNull LayoutInflater inflater, @Nullable ViewGroup container, @Nullable Bundle state) {
        binding = FragmentSettingsBinding.inflate(inflater, container, false);
        host().setPageTitle(spec.title());
        binding.saveButton.setOnClickListener(view -> save());
        load();
        return binding.getRoot();
    }

    private void load() {
        final String path;
        try { path = PathResolver.resolve(spec.listPath(), app().session(), null); }
        catch (IllegalArgumentException exception) { host().requestAppSelection(); return; }
        binding.progress.setVisibility(View.VISIBLE);
        track(app().repository().get(path, new LinkedHashMap<>(), result -> {
            if (binding == null) return;
            if (handleFailure(result, binding.getRoot())) { binding.progress.setVisibility(View.GONE); return; }
            renderSettings(Jsons.object(result.dataObject(), "settings"), Jsons.object(result.dataObject(), "setting_descriptors"));
            JsonObject policy = Jsons.object(result.dataObject(), "chat_polling_policy");
            binding.policyText.setText(policy.entrySet().isEmpty() ? "" : policyText(policy));
            if (app().session().role() == Role.ADMIN) loadFeatures();
            else binding.progress.setVisibility(View.GONE);
        }));
    }

    private void loadFeatures() {
        String path = "/api/admin/apps/" + app().session().selectedAppId() + "/features";
        track(app().repository().get(path, new LinkedHashMap<>(), result -> {
            if (binding == null) return;
            binding.progress.setVisibility(View.GONE);
            if (!result.isSuccessful()) return;
            renderFeatures(Jsons.object(result.dataObject(), "features"));
        }));
    }

    private void renderSettings(JsonObject settings, JsonObject descriptors) {
        binding.fieldsContainer.removeAllViews();
        settingViews.clear();
        settingTypes.clear();
        for (Map.Entry<String, JsonElement> entry : settings.entrySet()) {
            View field = createField(entry.getKey(), entry.getValue(), false, Jsons.object(descriptors, entry.getKey()));
            binding.fieldsContainer.addView(field, params());
            settingViews.put(entry.getKey(), field);
            settingTypes.put(entry.getKey(), entry.getValue());
        }
    }

    private void renderFeatures(JsonObject features) {
        if (features.entrySet().isEmpty()) return;
        android.widget.TextView heading = new android.widget.TextView(requireContext());
        heading.setText("功能开关");
        heading.setTextAppearance(com.google.android.material.R.style.TextAppearance_Material3_TitleMedium);
        LinearLayout.LayoutParams headingParams = params();
        headingParams.topMargin = dp(22);
        binding.fieldsContainer.addView(heading, headingParams);
        featureViews.clear();
        for (Map.Entry<String, JsonElement> entry : features.entrySet()) {
            View field = createField(entry.getKey(), entry.getValue(), true, new JsonObject());
            binding.fieldsContainer.addView(field, params());
            featureViews.put(entry.getKey(), field);
        }
    }

    private View createField(String key, JsonElement value, boolean feature, JsonObject descriptor) {
        String label = feature ? featureLabel(key) : Jsons.string(descriptor, "label");
        if (label.isEmpty()) label = DisplayText.label(key);
        String unit = Jsons.string(descriptor, "unit");
        String description = Jsons.string(descriptor, "description");
        if (feature || (value != null && value.isJsonPrimitive() && value.getAsJsonPrimitive().isBoolean())) {
            MaterialSwitch toggle = new MaterialSwitch(requireContext());
            toggle.setText(label);
            if (!description.isEmpty()) toggle.setContentDescription(label + "。" + description);
            toggle.setMinHeight(dp(48));
            try { toggle.setChecked(value.getAsBoolean()); } catch (RuntimeException ignored) { }
            return toggle;
        }
        TextInputLayout layout = new TextInputLayout(requireContext(), null, com.google.android.material.R.attr.textInputOutlinedStyle);
        layout.setHint(DisplayText.label(key));
        TextInputEditText input = new TextInputEditText(layout.getContext());
        boolean complex = value != null && (value.isJsonArray() || value.isJsonObject());
        if (complex) {
            return new StructuredJsonInput(requireContext(),
                FieldSpec.typed(key, label + (unit.isEmpty() ? "" : "（" + unit + "）"), FieldType.JSON, false), value);
        }
        layout.setHint(label);
        if (!unit.isEmpty()) layout.setSuffixText(unit);
        if (!description.isEmpty()) {
            layout.setHelperText(description);
            layout.setHelperTextEnabled(true);
        }
        input.setText(DisplayText.value(value));
        boolean number = value != null && value.isJsonPrimitive() && value.getAsJsonPrimitive().isNumber();
        input.setInputType(number ? InputType.TYPE_CLASS_NUMBER | InputType.TYPE_NUMBER_FLAG_DECIMAL | InputType.TYPE_NUMBER_FLAG_SIGNED
            : InputType.TYPE_CLASS_TEXT);
        input.setMinLines(1);
        input.setMaxLines(2);
        SafeTextInput.attach(layout, input);
        return layout;
    }

    private void save() {
        JsonObject settings = new JsonObject();
        try {
            for (Map.Entry<String, View> entry : settingViews.entrySet()) {
                settings.add(entry.getKey(), read(entry.getValue(), settingTypes.get(entry.getKey())));
            }
        } catch (IllegalArgumentException exception) {
            message(binding.getRoot(), exception.getMessage());
            return;
        }
        JsonObject body = new JsonObject();
        body.add("settings", settings);
        String path = PathResolver.resolve(spec.listPath(), app().session(), null);
        binding.progress.setVisibility(View.VISIBLE);
        binding.saveButton.setEnabled(false);
        track(app().repository().put(path, body, result -> {
            if (binding == null) return;
            if (handleFailure(result, binding.getRoot())) { finishSave(); return; }
            if (!featureViews.isEmpty()) saveFeatures();
            else {
                finishSave();
                message(binding.getRoot(), result.message().isEmpty() ? "规则已保存" : result.message());
                load();
            }
        }));
    }

    private void saveFeatures() {
        JsonObject features = new JsonObject();
        for (Map.Entry<String, View> entry : featureViews.entrySet()) {
            features.addProperty(entry.getKey(), ((MaterialSwitch) entry.getValue()).isChecked());
        }
        JsonObject body = new JsonObject();
        body.add("features", features);
        String path = "/api/admin/apps/" + app().session().selectedAppId() + "/features";
        track(app().repository().put(path, body, result -> {
            if (binding == null) return;
            finishSave();
            if (handleFailure(result, binding.getRoot())) return;
            message(binding.getRoot(), result.message().isEmpty() ? "规则与功能已保存" : result.message());
            load();
        }));
    }

    private JsonElement read(View view, JsonElement original) {
        if (view instanceof MaterialSwitch) return new com.google.gson.JsonPrimitive(((MaterialSwitch) view).isChecked());
        if (view instanceof StructuredJsonInput) return ((StructuredJsonInput) view).value();
        EditText input = ((TextInputLayout) view).getEditText();
        String text = input == null ? "" : input.getText().toString().trim();
        try {
            if (original != null && (original.isJsonArray() || original.isJsonObject())) return JsonParser.parseString(text);
            if (original != null && original.isJsonPrimitive() && original.getAsJsonPrimitive().isNumber()) {
                return text.contains(".") ? new com.google.gson.JsonPrimitive(Double.parseDouble(text)) : new com.google.gson.JsonPrimitive(Long.parseLong(text));
            }
            return new com.google.gson.JsonPrimitive(text);
        } catch (RuntimeException exception) {
            throw new IllegalArgumentException(DisplayText.label(findKey(view)) + "格式错误");
        }
    }

    private String findKey(View target) {
        for (Map.Entry<String, View> entry : settingViews.entrySet()) if (entry.getValue() == target) return entry.getKey();
        return "设置";
    }

    private LinearLayout.LayoutParams params() {
        LinearLayout.LayoutParams params = new LinearLayout.LayoutParams(-1, -2);
        params.topMargin = dp(10);
        return params;
    }

    private String featureLabel(String key) {
        Map<String, String> labels = new LinkedHashMap<>();
        labels.put("user_profile", "个人资料"); labels.put("documents", "文档中心"); labels.put("resources", "资源大厅");
        labels.put("forum", "论坛社区"); labels.put("messages", "消息好友"); labels.put("chat_rooms", "聊天室");
        labels.put("customer_service", "客服"); labels.put("cards", "卡密"); labels.put("commerce", "商城互动");
        labels.put("remote_files", "远程文件"); labels.put("feedback", "意见反馈");
        return labels.getOrDefault(key, key.replace('_', ' '));
    }

    private String policyText(JsonObject policy) {
        long effective = Jsons.longValue(policy, "effective_interval_ms");
        long minimum = Jsons.longValue(policy, "minimum_interval_ms");
        long maximum = Jsons.longValue(policy, "maximum_interval_ms");
        boolean locked = false;
        try { locked = policy.has("locked") && policy.get("locked").getAsBoolean(); } catch (RuntimeException ignored) { }
        return "聊天消息刷新：每 " + milliseconds(effective) + " 获取一次新消息\n"
            + "允许范围：" + milliseconds(minimum) + " 至 " + milliseconds(maximum) + "\n"
            + (locked ? "当前值已由上级强制锁定，下级不能修改。" : "当前层级可以在上级允许范围内调整。");
    }

    private String milliseconds(long value) {
        if (value > 0 && value % 1000 == 0) return value + " 毫秒（" + (value / 1000) + " 秒）";
        return value + " 毫秒";
    }

    private void finishSave() {
        binding.progress.setVisibility(View.GONE);
        binding.saveButton.setEnabled(true);
    }

    private int dp(int value) { return Math.round(value * getResources().getDisplayMetrics().density); }
    @Override public void onDestroyView() { binding = null; super.onDestroyView(); }
}
