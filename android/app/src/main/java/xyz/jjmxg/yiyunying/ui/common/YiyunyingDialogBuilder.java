package xyz.jjmxg.yiyunying.ui.common;

import android.content.Context;
import android.content.res.ColorStateList;
import android.graphics.Color;
import android.graphics.drawable.ColorDrawable;
import android.graphics.drawable.GradientDrawable;
import android.os.Build;
import android.view.Gravity;
import android.view.View;
import android.view.ViewGroup;
import android.view.Window;
import android.view.WindowManager;
import android.widget.Button;
import android.widget.LinearLayout;
import android.widget.TextView;
import android.text.TextUtils;
import android.util.TypedValue;

import androidx.annotation.StyleRes;
import androidx.appcompat.app.AlertDialog;
import androidx.core.content.ContextCompat;
import androidx.core.graphics.Insets;
import androidx.core.view.ViewCompat;
import androidx.core.view.WindowInsetsCompat;

import com.google.android.material.dialog.MaterialAlertDialogBuilder;
import com.google.android.material.button.MaterialButton;
import com.google.gson.JsonArray;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;
import com.google.gson.JsonParser;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.core.AppearanceStyleStore;
import xyz.jjmxg.yiyunying.core.RuntimeLanguage;
import xyz.jjmxg.yiyunying.data.api.ApiVisibleMessage;

/**
 * Global dialog surface used by all four app roles.
 *
 * Text, list and form dialogs are presented as bottom information cards so API records never
 * fall back to a raw, two-line system alert. Appearance work is attached to the decor view
 * instead of replacing OnShowListener; callers remain free to install validation handlers.
 */
public final class YiyunyingDialogBuilder extends MaterialAlertDialogBuilder {
    private final Context context;
    private boolean businessTitle;
    private boolean businessMessage;

    public YiyunyingDialogBuilder(Context context) {
        super(context);
        this.context = context;
    }

    public YiyunyingDialogBuilder(Context context, @StyleRes int overrideThemeResId) {
        super(context, overrideThemeResId);
        this.context = context;
    }

    @Override public AlertDialog create() {
        AlertDialog dialog = super.create();
        installAppearanceObserver(dialog);
        return dialog;
    }

    @Override public AlertDialog show() {
        AlertDialog dialog = create();
        prepareWindowBeforeShow(dialog.getWindow());
        dialog.show();
        applyAppearance(dialog);
        return dialog;
    }

    /** Sets a title originating from an account or business record, so it is never translated. */
    public YiyunyingDialogBuilder setBusinessTitle(CharSequence title) {
        businessTitle = true;
        super.setTitle(title);
        return this;
    }

    /** Sets user-authored or server-authored content, so runtime localization preserves it. */
    public YiyunyingDialogBuilder setBusinessMessage(CharSequence message) {
        businessMessage = true;
        setMessage(message);
        return this;
    }

    /**
     * API responses occasionally reach an old call site as serialized JSON. Converting them at
     * the shared dialog boundary keeps every role visual even before that individual screen is
     * migrated to a typed model.
     */
    @Override public YiyunyingDialogBuilder setMessage(CharSequence message) {
        String raw = message == null ? "" : message.toString().trim();
        View structured = structuredJsonView(raw);
        if (structured != null) {
            super.setView(structured);
            return this;
        }

        String safe = ApiVisibleMessage.visible(raw, 500, 500);
        if (!normalizedForComparison(raw).equals(normalizedForComparison(safe))) {
            super.setMessage(safe);
            return this;
        }

        structured = structuredMessageView(raw);
        if (structured != null) super.setView(structured);
        else super.setMessage(safe);
        return this;
    }

    private String normalizedForComparison(String value) {
        if (value == null) return "";
        return value.replace('\ufeff', ' ').replace('\u0000', ' ')
            .trim().replaceAll("\\s+", " ");
    }

    private View structuredMessageView(CharSequence message) {
        if (message == null) return null;
        String text = message.toString().trim();
        View json = structuredJsonView(text);
        if (json != null) return json;

        String[] rawLines = text.split("\\r?\\n");
        JsonObject record = new JsonObject();
        int plainIndex = 0;
        int visibleLines = 0;
        for (String rawLine : rawLines) {
            String line = rawLine == null ? "" : rawLine.trim();
            if (line.isEmpty()) continue;
            visibleLines++;
            int separator = fieldSeparator(line);
            if (separator > 0 && separator < line.length() - 1) {
                String key = line.substring(0, separator).trim();
                String value = line.substring(separator + 1).trim();
                if (!key.isEmpty() && !value.isEmpty()) {
                    putUnique(record, key, value);
                    continue;
                }
            }
            plainIndex++;
            putUnique(record, plainIndex == 1 ? "说明" : "补充说明", line);
        }
        return visibleLines >= 2 ? RecordDetailDialog.contentView(context, record) : null;
    }

    private View structuredJsonView(String text) {
        boolean object = text.startsWith("{") && text.endsWith("}");
        boolean array = text.startsWith("[") && text.endsWith("]");
        if (!object && !array) return null;
        try {
            JsonElement parsed = JsonParser.parseString(text);
            JsonObject record;
            if (parsed.isJsonObject()) {
                record = parsed.getAsJsonObject();
            } else if (parsed.isJsonArray()) {
                record = new JsonObject();
                JsonArray items = parsed.getAsJsonArray();
                record.add("内容", items);
            } else {
                return null;
            }
            return RecordDetailDialog.contentView(context, record);
        } catch (RuntimeException ignored) {
            return null;
        }
    }

    private int fieldSeparator(String value) {
        int chinese = value.indexOf('：');
        int ascii = value.indexOf(':');
        if (chinese < 0) return ascii;
        if (ascii < 0) return chinese;
        return Math.min(chinese, ascii);
    }

    private void putUnique(JsonObject record, String requestedKey, String value) {
        String key = requestedKey;
        int suffix = 2;
        while (record.has(key)) key = requestedKey + suffix++;
        record.addProperty(key, value);
    }

    private void applyAppearance(AlertDialog dialog) {
        Window window = dialog.getWindow();
        View decor = window == null ? null : window.getDecorView();
        if (decor == null || !decor.isAttachedToWindow()) return;
        if (Boolean.TRUE.equals(decor.getTag(R.id.tag_dialog_appearance_applied))) return;
        decor.setTag(R.id.tag_dialog_appearance_applied, Boolean.TRUE);
        try {
            styleWindow(window, decor);
            styleCard(decor);
            styleButtons(dialog);
            protectBusinessText(decor);
            RuntimeLanguage.applyTree(context, decor);
            AppearanceStyleStore.applyFontTree(context, decor);
        } catch (RuntimeException | LinkageError error) {
            // Allow a later attach/show pass to retry instead of leaving a half-styled dialog.
            decor.setTag(R.id.tag_dialog_appearance_applied, null);
            throw error;
        }
    }

    private void protectBusinessText(View decor) {
        if (businessTitle) {
            View title = findPanel(decor, "alertTitle");
            RuntimeLanguage.protectDynamicText(title);
        }
        if (businessMessage) {
            TextView message = decor.findViewById(android.R.id.message);
            RuntimeLanguage.protectDynamicText(message);
        }
    }

    private void installAppearanceObserver(AlertDialog dialog) {
        Window window = dialog.getWindow();
        if (window == null) return;
        View decor = window.getDecorView();
        decor.addOnAttachStateChangeListener(new View.OnAttachStateChangeListener() {
            @Override public void onViewAttachedToWindow(View view) {
                applyAppearance(dialog);
            }

            @Override public void onViewDetachedFromWindow(View view) { }
        });
    }

    private void styleWindow(Window window, View decor) {
        if (window == null) return;
        prepareWindowBeforeShow(window);
        WindowManager.LayoutParams attributes = window.getAttributes();
        attributes.gravity = Gravity.BOTTOM;
        attributes.width = WindowManager.LayoutParams.MATCH_PARENT;
        attributes.height = WindowManager.LayoutParams.WRAP_CONTENT;
        attributes.dimAmount = 0.34f;
        if (Build.VERSION.SDK_INT >= 31) {
            attributes.setBlurBehindRadius(dp(28));
            window.addFlags(WindowManager.LayoutParams.FLAG_BLUR_BEHIND);
            window.setBackgroundBlurRadius(dp(34));
        }
        window.setAttributes(attributes);
        window.addFlags(WindowManager.LayoutParams.FLAG_DIM_BEHIND);
        window.setLayout(WindowManager.LayoutParams.MATCH_PARENT, WindowManager.LayoutParams.WRAP_CONTENT);
        installSafeInsets(decor);
        if (decor.getTag(R.id.tag_dialog_card_animated) == null) {
            decor.setTag(R.id.tag_dialog_card_animated, Boolean.TRUE);
            decor.setAlpha(0f);
            decor.setTranslationY(dp(28));
            decor.animate().alpha(1f).translationY(0f).setDuration(190L).start();
        }
    }

    private void prepareWindowBeforeShow(Window window) {
        if (window == null) return;
        window.setBackgroundDrawable(new ColorDrawable(Color.TRANSPARENT));
        window.setSoftInputMode(WindowManager.LayoutParams.SOFT_INPUT_ADJUST_RESIZE);
        WindowManager.LayoutParams attributes = window.getAttributes();
        attributes.gravity = Gravity.BOTTOM;
        attributes.width = WindowManager.LayoutParams.MATCH_PARENT;
        attributes.height = WindowManager.LayoutParams.WRAP_CONTENT;
        window.setAttributes(attributes);
    }

    private void installSafeInsets(View decor) {
        // Give the first rendered frame the same safe spacing as the inset callback. This avoids
        // a visible jump and protects the lower corners even before Android dispatches insets.
        decor.setPadding(dp(12), 0, dp(12), dp(12));
        if (decor.getTag(R.id.tag_dialog_safe_insets) == null) {
            decor.setTag(R.id.tag_dialog_safe_insets, Boolean.TRUE);
            ViewCompat.setOnApplyWindowInsetsListener(decor, (view, insets) -> {
                Insets safe = insets.getInsets(
                    WindowInsetsCompat.Type.navigationBars() | WindowInsetsCompat.Type.displayCutout());
                view.setPadding(dp(12) + safe.left, 0, dp(12) + safe.right,
                    dp(12) + Math.max(0, safe.bottom));
                return insets;
            });
            ViewCompat.requestApplyInsets(decor);
        }
    }

    private void styleCard(View decor) {
        View panel = findPanel(decor, "parentPanel");
        if (panel == null) panel = decor.findViewById(android.R.id.content);
        if (panel == null) return;
        GradientDrawable background = new GradientDrawable();
        int surface = ContextCompat.getColor(context, R.color.surface_container_high);
        background.setColor((surface & 0x00FFFFFF) | 0xEE000000);
        background.setCornerRadii(new float[]{
            dp(18), dp(18), dp(18), dp(18), dp(18), dp(18), dp(18), dp(18)
        });
        background.setStroke(dp(1), ContextCompat.getColor(context, R.color.outline_variant));
        panel.setBackground(background);
        panel.setClipToOutline(false);
        panel.setOutlineProvider(null);
        if (panel instanceof ViewGroup) {
            ((ViewGroup) panel).setClipChildren(false);
            ((ViewGroup) panel).setClipToPadding(false);
        }
        panel.setElevation(dp(14));

        View titlePanel = findPanel(decor, "topPanel");
        if (titlePanel != null) titlePanel.setPadding(dp(8), dp(6), dp(8), 0);
        View contentPanel = findPanel(decor, "contentPanel");
        if (contentPanel != null) contentPanel.setPadding(dp(2), 0, dp(2), 0);
        View buttonPanel = findPanel(decor, "buttonPanel");
        if (buttonPanel != null) {
            buttonPanel.setPadding(dp(8), dp(8), dp(8), dp(10));
            buttonPanel.setClipToOutline(false);
            if (buttonPanel instanceof ViewGroup) {
                ((ViewGroup) buttonPanel).setClipChildren(false);
                ((ViewGroup) buttonPanel).setClipToPadding(false);
            }
        }
    }

    private void styleButtons(AlertDialog dialog) {
        Button positive = dialog.getButton(AlertDialog.BUTTON_POSITIVE);
        Button negative = dialog.getButton(AlertDialog.BUTTON_NEGATIVE);
        Button neutral = dialog.getButton(AlertDialog.BUTTON_NEUTRAL);
        styleButton(positive, true);
        styleButton(negative, false);
        styleButton(neutral, false);
        equalizeVisibleButtons(positive, negative, neutral);
    }

    private void styleButton(Button button, boolean primary) {
        if (button == null || button.getVisibility() != View.VISIBLE) return;
        button.setAllCaps(false);
        button.setTextSize(TypedValue.COMPLEX_UNIT_SP, 14);
        button.setSingleLine(false);
        button.setMaxLines(2);
        button.setGravity(Gravity.CENTER);
        button.setEllipsize(TextUtils.TruncateAt.END);
        button.setMinWidth(0);
        button.setMinimumWidth(0);
        button.setMinHeight(dp(50));
        button.setMinimumHeight(dp(50));
        button.setPadding(dp(8), dp(4), dp(8), dp(4));
        button.setTextColor(primary ? ThemeColors.onPrimary(context) : ThemeColors.primary(context));
        if (button instanceof MaterialButton) {
            GlassBottomSheet.styleActionButton((MaterialButton) button, context, primary, 16);
        } else {
            GradientDrawable background = new GradientDrawable();
            background.setColor(primary ? ThemeColors.primary(context) : Color.TRANSPARENT);
            background.setCornerRadius(dp(16));
            if (!primary) background.setStroke(dp(1), ContextCompat.getColor(context, R.color.outline_variant));
            button.setBackgroundTintList(null);
            button.setBackground(background);
        }
        ViewGroup.LayoutParams raw = button.getLayoutParams();
        if (raw instanceof LinearLayout.LayoutParams) {
            LinearLayout.LayoutParams params = (LinearLayout.LayoutParams) raw;
            params.leftMargin = dp(4);
            params.rightMargin = dp(4);
            params.topMargin = dp(6);
            params.bottomMargin = dp(6);
            button.setLayoutParams(params);
        }
    }

    /** Keeps cancel/copy/confirm actions visually separate and gives every action a stable hit area. */
    private void equalizeVisibleButtons(Button... buttons) {
        int visibleCount = 0;
        for (Button button : buttons) {
            if (button != null && button.getVisibility() == View.VISIBLE) visibleCount++;
        }
        if (visibleCount < 2) return;
        for (Button button : buttons) {
            if (button == null || button.getVisibility() != View.VISIBLE) continue;
            ViewGroup.LayoutParams raw = button.getLayoutParams();
            if (!(raw instanceof LinearLayout.LayoutParams)) continue;
            LinearLayout.LayoutParams params = (LinearLayout.LayoutParams) raw;
            params.width = 0;
            params.weight = 1f;
            button.setLayoutParams(params);
        }
    }

    private View findPanel(View root, String name) {
        int id = context.getResources().getIdentifier(name, "id", "android");
        return id == 0 ? null : root.findViewById(id);
    }

    private int dp(int value) {
        return Math.round(value * context.getResources().getDisplayMetrics().density);
    }
}
