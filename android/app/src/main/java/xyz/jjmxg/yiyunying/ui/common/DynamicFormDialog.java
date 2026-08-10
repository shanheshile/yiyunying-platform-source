package xyz.jjmxg.yiyunying.ui.common;

import android.content.Context;
import android.content.res.ColorStateList;
import android.text.InputType;
import android.view.View;
import android.widget.Button;
import android.widget.EditText;
import android.widget.LinearLayout;
import android.widget.ScrollView;

import androidx.appcompat.app.AlertDialog;
import androidx.core.content.ContextCompat;
import androidx.core.widget.TextViewCompat;

import com.google.android.material.button.MaterialButton;
import com.google.android.material.materialswitch.MaterialSwitch;
import com.google.android.material.textfield.TextInputEditText;
import com.google.android.material.textfield.TextInputLayout;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;

import java.util.LinkedHashMap;
import java.util.Map;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.domain.module.ActionSpec;
import xyz.jjmxg.yiyunying.domain.module.FieldSpec;
import xyz.jjmxg.yiyunying.domain.module.FieldType;

public final class DynamicFormDialog {
    public interface Listener {
        void onSubmit(JsonObject body);
    }

    private DynamicFormDialog() {
    }

    public static void show(Context context, ActionSpec action, JsonObject item, Listener listener) {
        if (action.fields().isEmpty()) {
            if (action.confirmationRequired()) {
                AlertDialog dialog = new YiyunyingDialogBuilder(context)
                    .setTitle(action.title())
                    .setMessage(action.destructive() ? "此操作会改变或删除数据，是否继续？" : "确认执行此操作？")
                    .setNegativeButton("取消", null)
                    .setPositiveButton("确定", (ignoredDialog, which) -> listener.onSubmit(new JsonObject()))
                    .create();
                dialog.setOnShowListener(ignored -> styleSubmit(dialog, action));
                dialog.show();
            } else {
                listener.onSubmit(new JsonObject());
            }
            return;
        }

        ScrollView scroll = new ScrollView(context);
        LinearLayout container = new LinearLayout(context);
        container.setOrientation(LinearLayout.VERTICAL);
        int padding = dp(context, 20);
        container.setPadding(padding, dp(context, 8), padding, dp(context, 8));
        scroll.addView(container, new ScrollView.LayoutParams(-1, -2));

        Map<FieldSpec, View> controls = new LinkedHashMap<>();
        for (FieldSpec field : action.fields()) {
            View control = createControl(context, field, item);
            LinearLayout.LayoutParams params = new LinearLayout.LayoutParams(-1, -2);
            params.topMargin = dp(context, 10);
            container.addView(control, params);
            controls.put(field, control);
        }

        AlertDialog dialog = new YiyunyingDialogBuilder(context)
            .setTitle(action.title())
            .setView(scroll)
            .setNegativeButton("取消", null)
            .setPositiveButton("确定", null)
            .create();
        dialog.setOnShowListener(ignored -> {
            styleSubmit(dialog, action);
            dialog.getButton(AlertDialog.BUTTON_POSITIVE).setOnClickListener(view -> {
                try {
                    JsonObject body = collect(controls);
                    dialog.dismiss();
                    listener.onSubmit(body);
                } catch (IllegalArgumentException validationFailure) {
                    // collect() marks the exact invalid field; leaving the dialog open is intentional.
                }
            });
        });
        dialog.show();
    }

    private static void styleSubmit(AlertDialog dialog, ActionSpec action) {
        Button positive = dialog.getButton(AlertDialog.BUTTON_POSITIVE);
        if (positive == null) return;
        if (positive instanceof MaterialButton) {
            ActionIconResolver.apply((MaterialButton) positive, action.title(), 0, true);
            return;
        }
        ActionIconResolver.apply(positive, action.title(), 0);
        if (ActionIconResolver.destructive(action.title())) {
            positive.setBackgroundTintList(ColorStateList.valueOf(
                ContextCompat.getColor(positive.getContext(), R.color.error)));
            positive.setTextColor(ContextCompat.getColor(positive.getContext(), R.color.white));
            TextViewCompat.setCompoundDrawableTintList(positive, ColorStateList.valueOf(
                ContextCompat.getColor(positive.getContext(), R.color.white)));
        }
    }

    private static View createControl(Context context, FieldSpec field, JsonObject item) {
        String initial = field.defaultValue();
        JsonElement initialJson = null;
        if (item != null && item.has(field.key()) && !item.get(field.key()).isJsonNull()) {
            JsonElement existing = item.get(field.key());
            initial = field.type() == FieldType.JSON ? Jsons.PRETTY.toJson(existing) : DisplayText.value(existing);
            initialJson = existing;
        } else if (field.type() == FieldType.JSON && "changes".equals(field.key()) && item != null) {
            initialJson = item;
        } else if (field.type() == FieldType.JSON && initial != null && !initial.trim().isEmpty()) {
            try { initialJson = com.google.gson.JsonParser.parseString(initial); } catch (RuntimeException ignored) { }
        }
        if (field.type() == FieldType.JSON && "attachments".equals(field.key())) {
            return new AttachmentJsonInput(context, field, initialJson);
        }
        if (field.type() == FieldType.JSON) return new StructuredJsonInput(context, field, initialJson);
        if (field.type() == FieldType.BOOLEAN) {
            MaterialSwitch toggle = new MaterialSwitch(context);
            toggle.setText(field.label());
            toggle.setMinHeight(dp(context, 48));
            toggle.setChecked("1".equals(initial) || "true".equalsIgnoreCase(initial) || "是".equals(initial));
            return toggle;
        }
        TextInputLayout layout = new TextInputLayout(context, null, com.google.android.material.R.attr.textInputOutlinedStyle);
        layout.setHint(field.label() + (field.required() ? " *" : ""));
        TextInputEditText input = new TextInputEditText(layout.getContext());
        input.setText(initial);
        input.setMaxLines(field.type() == FieldType.MULTILINE || field.type() == FieldType.JSON ? 12 : 2);
        input.setMinLines(field.type() == FieldType.MULTILINE || field.type() == FieldType.JSON ? 4 : 1);
        input.setInputType(inputType(field.type()));
        if (field.type() == FieldType.PASSWORD) {
            layout.setEndIconMode(TextInputLayout.END_ICON_PASSWORD_TOGGLE);
        }
        SafeTextInput.attach(layout, input);
        return layout;
    }

    private static JsonObject collect(Map<FieldSpec, View> controls) {
        JsonObject body = new JsonObject();
        for (Map.Entry<FieldSpec, View> entry : controls.entrySet()) {
            FieldSpec field = entry.getKey();
            View control = entry.getValue();
            if (control instanceof MaterialSwitch) {
                body.addProperty(field.key(), ((MaterialSwitch) control).isChecked());
                continue;
            }
            if (control instanceof AttachmentJsonInput) {
                AttachmentJsonInput attachments = (AttachmentJsonInput) control;
                try {
                    JsonElement value = attachments.value();
                    if (field.required() && attachments.isEmpty()) {
                        attachments.showError(field.label() + "不能为空");
                        throw new IllegalArgumentException(field.label() + "不能为空");
                    }
                    if (!attachments.isEmpty()) body.add(field.key(), value);
                } catch (IllegalArgumentException exception) {
                    attachments.showError(exception.getMessage());
                    throw exception;
                }
                continue;
            }
            if (control instanceof StructuredJsonInput) {
                StructuredJsonInput structured = (StructuredJsonInput) control;
                try {
                    JsonElement parsed = structured.value();
                    if (field.required() && structured.isEmpty()) {
                        structured.showError(field.label() + "不能为空");
                        throw new IllegalArgumentException(field.label() + "不能为空");
                    }
                    if ("changes".equals(field.key()) && parsed.isJsonObject()) {
                        parsed.getAsJsonObject().entrySet().forEach(e -> body.add(e.getKey(), e.getValue()));
                    } else if (!structured.isEmpty()) {
                        body.add(field.key(), parsed);
                    }
                } catch (IllegalArgumentException exception) {
                    structured.showError(exception.getMessage());
                    throw exception;
                }
                continue;
            }
            TextInputLayout layout = (TextInputLayout) control;
            EditText input = layout.getEditText();
            String value = input == null ? "" : input.getText().toString().trim();
            layout.setError(null);
            if (field.required() && value.isEmpty()) {
                layout.setError(field.label() + "不能为空");
                throw new IllegalArgumentException("请填写必填项");
            }
            if (value.isEmpty()) continue;
            try {
                switch (field.type()) {
                    case INTEGER:
                        body.addProperty(field.key(), Long.parseLong(value));
                        break;
                    case DECIMAL:
                        body.addProperty(field.key(), Double.parseDouble(value));
                        break;
                    default:
                        body.addProperty(field.key(), value);
                }
            } catch (RuntimeException exception) {
                layout.setError(field.label() + "格式错误");
                throw new IllegalArgumentException(field.label() + "格式错误");
            }
        }
        return body;
    }

    private static int inputType(FieldType type) {
        switch (type) {
            case PASSWORD: return InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_VARIATION_PASSWORD;
            case INTEGER: return InputType.TYPE_CLASS_NUMBER | InputType.TYPE_NUMBER_FLAG_SIGNED;
            case DECIMAL: return InputType.TYPE_CLASS_NUMBER | InputType.TYPE_NUMBER_FLAG_DECIMAL;
            case MULTILINE: case JSON: return InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_FLAG_MULTI_LINE;
            case DATE_TIME: return InputType.TYPE_CLASS_DATETIME;
            default: return InputType.TYPE_CLASS_TEXT;
        }
    }

    private static int dp(Context context, int value) {
        return Math.round(value * context.getResources().getDisplayMetrics().density);
    }
}
