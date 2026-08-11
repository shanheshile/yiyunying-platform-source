package xyz.jjmxg.yiyunying.ui.common;

import android.app.Activity;
import android.content.ClipData;
import android.content.ClipboardManager;
import android.content.Context;
import android.graphics.Color;
import android.graphics.Typeface;
import android.graphics.drawable.GradientDrawable;
import android.view.Gravity;
import android.view.View;
import android.view.ViewGroup;
import android.widget.FrameLayout;
import android.widget.LinearLayout;
import android.widget.ScrollView;
import android.widget.TextView;

import com.google.android.material.bottomsheet.BottomSheetDialog;
import com.google.android.material.button.MaterialButton;
import com.google.gson.JsonArray;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;
import com.google.gson.JsonParser;

import java.util.ArrayList;
import java.util.Arrays;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Locale;
import java.util.Map;
import java.util.concurrent.atomic.AtomicBoolean;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.core.CrashReporter;
import xyz.jjmxg.yiyunying.core.RuntimeLanguage;
import xyz.jjmxg.yiyunying.data.api.ApiVisibleMessage;
import xyz.jjmxg.yiyunying.data.api.Jsons;

public final class RecordDetailDialog {
    private RecordDetailDialog() { }

    public static void show(Context context, String title, JsonObject item) {
        show(context, title, item, null, null);
    }

    public static String readableText(JsonObject item) {
        if (item == null) return "暂无内容";
        String value = readable(item, 0).trim();
        return value.isEmpty() ? "暂无内容" : value;
    }

    public static void show(Context context, String title, JsonObject item, Runnable manageAction) {
        show(context, title, item, "管理操作", manageAction);
    }

    public static void show(Context context, String title, JsonObject item,
                            String actionLabel, Runnable action) {
        String readable = readableText(item);
        List<SheetAction> actions = new ArrayList<>();
        actions.add(new SheetAction("复制详情", false, false, () -> {
            ClipboardManager manager = (ClipboardManager) context.getSystemService(Context.CLIPBOARD_SERVICE);
            if (manager != null) manager.setPrimaryClip(ClipData.newPlainText(title, readable));
        }));
        if (action == null) {
            actions.add(new SheetAction("关闭", true, true, null));
        } else {
            actions.add(new SheetAction("取消", false, true, null));
            actions.add(new SheetAction(actionLabel == null || actionLabel.trim().isEmpty() ? "操作" : actionLabel,
                true, true, action));
        }
        showSheet(context, title, item, actions);
    }

    /**
     * Shows a review card with two clearly separated decisions and no unrelated copy action.
     * Picker flows use this so previewing an item never looks like a database record editor.
     */
    public static void showChoice(Context context, String title, JsonObject item,
                                  String negativeLabel, Runnable negativeAction,
                                  String positiveLabel, Runnable positiveAction) {
        List<SheetAction> actions = new ArrayList<>();
        actions.add(new SheetAction(
            negativeLabel == null || negativeLabel.trim().isEmpty() ? "关闭" : negativeLabel,
            false,
            true,
            negativeAction
        ));
        actions.add(new SheetAction(
            positiveLabel == null || positiveLabel.trim().isEmpty() ? "确定" : positiveLabel,
            true,
            true,
            positiveAction
        ));
        showSheet(context, title, item, actions);
    }

    /** Shows a structured review card with two explicit business decisions. */
    public static void showDecision(Context context, String title, JsonObject item,
                                    String negativeLabel, Runnable negativeAction,
                                    String positiveLabel, Runnable positiveAction) {
        List<SheetAction> actions = new ArrayList<>();
        actions.add(new SheetAction("暂不处理", false, true, null));
        actions.add(new SheetAction(negativeLabel, false, true, negativeAction));
        actions.add(new SheetAction(positiveLabel, true, true, positiveAction));
        showSheet(context, title, item, actions);
    }

    private static void showSheet(Context context, String title, JsonObject item, List<SheetAction> actions) {
        Activity activity = context instanceof Activity ? (Activity) context : null;
        if (activity != null && (activity.isFinishing() || activity.isDestroyed())) return;
        JsonObject snapshot = item == null ? new JsonObject() : item.deepCopy();
        BottomSheetDialog dialog = new BottomSheetDialog(context);
        LinearLayout root = new LinearLayout(context);
        root.setOrientation(LinearLayout.VERTICAL);
        GradientDrawable background = new GradientDrawable();
        background.setColor(context.getColor(R.color.glass_surface_strong));
        float radius = dp(context, 22);
        background.setCornerRadius(radius);
        background.setStroke(dp(context, 1), context.getColor(R.color.glass_outline));
        root.setBackground(background);

        FrameLayout handleBox = new FrameLayout(context);
        View handle = new View(context);
        GradientDrawable handleBackground = new GradientDrawable();
        handleBackground.setColor(context.getColor(R.color.outline_variant));
        handleBackground.setCornerRadius(dp(context, 3));
        handle.setBackground(handleBackground);
        FrameLayout.LayoutParams handleParams = new FrameLayout.LayoutParams(dp(context, 42), dp(context, 4), Gravity.CENTER);
        handleBox.addView(handle, handleParams);
        root.addView(handleBox, new LinearLayout.LayoutParams(-1, dp(context, 24)));

        TextView heading = new TextView(context);
        RuntimeLanguage.setDynamicText(heading, title == null || title.trim().isEmpty() ? "详情" : title.trim());
        heading.setTextAppearance(com.google.android.material.R.style.TextAppearance_Material3_TitleLarge);
        heading.setTextColor(context.getColor(R.color.on_surface));
        heading.setTypeface(Typeface.DEFAULT, Typeface.BOLD);
        heading.setMaxLines(2);
        heading.setPadding(dp(context, 20), dp(context, 4), dp(context, 20), dp(context, 12));
        root.addView(heading, new LinearLayout.LayoutParams(-1, -2));

        ScrollView scroll = contentView(context, snapshot);
        scroll.setFillViewport(false);
        scroll.setOverScrollMode(View.OVER_SCROLL_IF_CONTENT_SCROLLS);
        root.addView(scroll, new LinearLayout.LayoutParams(-1, -2));

        LinearLayout actionArea = new LinearLayout(context);
        boolean horizontalActions = actions.size() <= 2;
        actionArea.setOrientation(horizontalActions ? LinearLayout.HORIZONTAL : LinearLayout.VERTICAL);
        actionArea.setGravity(Gravity.CENTER_VERTICAL);
        actionArea.setPadding(dp(context, 16), dp(context, 8), dp(context, 16), dp(context, 16));
        actionArea.setBackgroundColor(Color.TRANSPARENT);
        AtomicBoolean actionHandled = new AtomicBoolean(false);
        for (SheetAction action : actions) {
            MaterialButton button = action.primary
                ? new MaterialButton(context)
                : new MaterialButton(context, null, com.google.android.material.R.attr.materialButtonOutlinedStyle);
            RuntimeLanguage.setDynamicText(button, action.label == null ? "操作" : action.label);
            button.setAllCaps(false);
            button.setTextSize(horizontalActions ? 13f : 13.5f);
            button.setMaxLines(1);
            button.setEllipsize(android.text.TextUtils.TruncateAt.END);
            button.setMinHeight(dp(context, 48));
            button.setMinWidth(dp(context, 48));
            button.setGravity(Gravity.CENTER);
            button.setPadding(dp(context, 8), 0, dp(context, 8), 0);
            GlassBottomSheet.styleActionButton(button, context, action.primary, 14);
            ActionIconResolver.apply(button, action.label, 0, action.primary);
            button.setOnClickListener(view -> {
                if (action.callback != null && !actionHandled.compareAndSet(false, true)) return;
                if (activity != null && (activity.isFinishing() || activity.isDestroyed())) return;
                if (action.dismiss) dialog.dismiss();
                if (action.callback != null) {
                    try {
                        action.callback.run();
                    } catch (RuntimeException | LinkageError exception) {
                        CrashReporter.record("执行记录详情操作", exception);
                    }
                }
            });
            LinearLayout.LayoutParams buttonParams = horizontalActions
                ? new LinearLayout.LayoutParams(0, dp(context, 48), 1f)
                : new LinearLayout.LayoutParams(-1, dp(context, 48));
            if (horizontalActions) buttonParams.leftMargin = dp(context, 4);
            else buttonParams.topMargin = dp(context, 6);
            actionArea.addView(button, buttonParams);
        }
        root.addView(actionArea, new LinearLayout.LayoutParams(-1, -2));
        dialog.setContentView(root);
        GlassBottomSheet.prepareFloating(dialog, context, 0.84f, false);
        try {
            dialog.show();
        } catch (RuntimeException | LinkageError exception) {
            CrashReporter.record("显示记录详情", exception);
        }
    }

    private static final class SheetAction {
        final String label;
        final boolean primary;
        final boolean dismiss;
        final Runnable callback;

        SheetAction(String label, boolean primary, boolean dismiss, Runnable callback) {
            this.label = label;
            this.primary = primary;
            this.dismiss = dismiss;
            this.callback = callback;
        }
    }

    public static ScrollView contentView(Context context, JsonObject item) {
        ScrollView scroll = new ScrollView(context);
        LinearLayout content = new LinearLayout(context);
        content.setOrientation(LinearLayout.VERTICAL);
        int horizontal = dp(context, 20);
        content.setPadding(horizontal, dp(context, 8), horizontal, dp(context, 18));
        renderInto(context, content, item);
        scroll.addView(content, new ScrollView.LayoutParams(-1, -2));
        return scroll;
    }

    /**
     * Renders an API record into an existing page using the same semantic presentation as
     * bottom-sheet details. Management and user surfaces must share this path so a response can
     * never fall back to raw JSON merely because it was opened from a different role.
     */
    public static void renderInto(Context context, LinearLayout parent, JsonObject item) {
        parent.removeAllViews();
        appendObject(context, parent, item == null ? new JsonObject() : item, 0);
    }

    private static void appendObject(Context context, LinearLayout parent, JsonObject object, int depth) {
        int visibleCount = 0;
        for (Map.Entry<String, JsonElement> entry : visibleEntries(object)) {
            JsonElement value = normalize(entry.getValue());
            if (isMediaCollection(entry.getKey()) && value.isJsonArray()) {
                appendMediaSection(context, parent, DisplayText.label(entry.getKey()), value.getAsJsonArray(), depth);
                visibleCount++;
                continue;
            }
            if (value.isJsonObject() || value.isJsonArray()) {
                appendSection(context, parent, entry.getKey(), DisplayText.label(entry.getKey()), value, depth);
            } else {
                appendValue(context, parent, entry.getKey(), DisplayText.label(entry.getKey()),
                    safeFieldValue(entry.getKey(), value), depth);
            }
            visibleCount++;
        }
        if (visibleCount == 0) appendEmpty(context, parent, depth);
    }

    private static void appendMediaSection(Context context, LinearLayout parent, String title,
                                           JsonArray attachments, int depth) {
        TextView heading = new TextView(context);
        heading.setText(title);
        heading.setTextAppearance(com.google.android.material.R.style.TextAppearance_Material3_TitleSmall);
        heading.setTextColor(ThemeColors.primary(context));
        heading.setPadding(depth * dp(context, 10), dp(context, 14), 0, dp(context, 5));
        parent.addView(heading, new LinearLayout.LayoutParams(-1, -2));
        if (attachments.isEmpty()) {
            appendEmpty(context, parent, depth + 1);
            return;
        }
        LinearLayout media = new LinearLayout(context);
        media.setOrientation(LinearLayout.VERTICAL);
        LinearLayout.LayoutParams params = new LinearLayout.LayoutParams(-1, -2);
        params.leftMargin = (depth + 1) * dp(context, 10);
        parent.addView(media, params);
        MediaViewRenderer.render(context, media, attachments);
    }

    private static void appendSection(Context context, LinearLayout parent, String rawKey,
                                      String title, JsonElement value, int depth) {
        TextView heading = new TextView(context);
        heading.setText(title);
        heading.setTextAppearance(com.google.android.material.R.style.TextAppearance_Material3_TitleSmall);
        heading.setTextColor(ThemeColors.primary(context));
        heading.setPadding(depth * dp(context, 10), dp(context, 14), 0, dp(context, 5));
        parent.addView(heading, new LinearLayout.LayoutParams(-1, -2));
        if (value.isJsonObject()) {
            appendObject(context, parent, value.getAsJsonObject(), depth + 1);
            return;
        }
        JsonArray array = value.getAsJsonArray();
        int visibleCount = 0;
        for (int index = 0; index < array.size(); index++) {
            JsonElement child = normalize(array.get(index));
            if (!isMeaningfulValue(child, rawKey)) continue;
            String itemTitle = arrayItemTitle(rawKey, child, index);
            if (child.isJsonObject() || child.isJsonArray()) {
                appendSection(context, parent, rawKey, itemTitle, child, depth + 1);
            }
            else appendValue(context, parent, null, itemTitle, DisplayText.value(child), depth + 1);
            visibleCount++;
        }
        if (visibleCount == 0) appendEmpty(context, parent, depth + 1);
    }

    private static void appendValue(Context context, LinearLayout parent, String rawKey,
                                    String label, String value, int depth) {
        LinearLayout row = new LinearLayout(context);
        row.setGravity(Gravity.TOP);
        row.setOrientation(LinearLayout.VERTICAL);
        row.setPadding(depth * dp(context, 10), dp(context, 9), 0, dp(context, 9));
        TextView key = new TextView(context);
        key.setText(label);
        key.setTextAppearance(com.google.android.material.R.style.TextAppearance_Material3_LabelLarge);
        key.setTextColor(context.getColor(R.color.on_surface_variant));
        row.addView(key, new LinearLayout.LayoutParams(-1, -2));
        TextView text = new TextView(context);
        String visibleValue = value == null || value.isEmpty() ? "-" : value;
        if (DisplayText.isBusinessDataField(rawKey)) {
            text.setText(visibleValue);
            RuntimeLanguage.protectDynamicText(text);
        } else {
            RuntimeLanguage.setDynamicText(text, visibleValue);
        }
        text.setTextAppearance(com.google.android.material.R.style.TextAppearance_Material3_BodyMedium);
        text.setTextColor(context.getColor(R.color.on_surface));
        text.setTextIsSelectable(true);
        text.setLineSpacing(0f, 1.08f);
        LinearLayout.LayoutParams textParams = new LinearLayout.LayoutParams(-1, -2);
        textParams.topMargin = dp(context, 4);
        row.addView(text, textParams);
        parent.addView(row, new LinearLayout.LayoutParams(-1, -2));
        View divider = new View(context);
        divider.setBackgroundColor(context.getColor(R.color.surface_container_high));
        LinearLayout.LayoutParams dividerParams = new LinearLayout.LayoutParams(-1, dp(context, 1));
        dividerParams.leftMargin = depth * dp(context, 10);
        parent.addView(divider, dividerParams);
    }

    private static void appendEmpty(Context context, LinearLayout parent, int depth) {
        TextView empty = new TextView(context);
        empty.setText("暂无内容");
        empty.setTextColor(context.getColor(R.color.on_surface_variant));
        empty.setTypeface(Typeface.DEFAULT, Typeface.ITALIC);
        empty.setPadding(depth * dp(context, 10), dp(context, 6), 0, dp(context, 6));
        parent.addView(empty, new LinearLayout.LayoutParams(-1, -2));
    }

    private static JsonElement normalize(JsonElement value) {
        if (value == null || value.isJsonNull() || !value.isJsonPrimitive()
            || !value.getAsJsonPrimitive().isString()) return value;
        String text = value.getAsString().trim();
        if (!(text.startsWith("{") && text.endsWith("}")) && !(text.startsWith("[") && text.endsWith("]"))) return value;
        try { return JsonParser.parseString(text); }
        catch (RuntimeException ignored) { return value; }
    }

    private static String readable(JsonElement value, int depth) {
        return readable(value, depth, "");
    }

    private static String readable(JsonElement value, int depth, String collectionKey) {
        StringBuilder result = new StringBuilder();
        if (value == null || value.isJsonNull()) return "-";
        value = normalize(value);
        String indent = indent(depth);
        if (value.isJsonObject()) {
            for (Map.Entry<String, JsonElement> entry : visibleEntries(value.getAsJsonObject())) {
                JsonElement child = normalize(entry.getValue());
                if (isMediaCollection(entry.getKey()) && child.isJsonArray()) {
                    result.append(indent).append(DisplayText.label(entry.getKey())).append("：\n")
                        .append(readableAttachments(child.getAsJsonArray(), depth + 1));
                    continue;
                }
                if (child.isJsonObject() || child.isJsonArray()) {
                    result.append(indent).append(DisplayText.label(entry.getKey())).append("：\n")
                        .append(readable(child, depth + 1, entry.getKey()));
                } else {
                    result.append(indent).append(DisplayText.label(entry.getKey())).append("：")
                        .append(safeFieldValue(entry.getKey(), child)).append('\n');
                }
            }
        } else if (value.isJsonArray()) {
            JsonArray array = value.getAsJsonArray();
            for (int index = 0; index < array.size(); index++) {
                JsonElement child = normalize(array.get(index));
                if (!isMeaningfulValue(child, collectionKey)) continue;
                String childText = readable(child, depth + 1, collectionKey);
                if (childText.trim().isEmpty()) continue;
                result.append(indent).append(arrayItemTitle(collectionKey, child, index)).append("：\n")
                    .append(childText);
            }
        } else {
            String primitive = DisplayText.value(value);
            if (!primitive.trim().isEmpty() && !"-".equals(primitive)) {
                result.append(indent).append(primitive).append('\n');
            }
        }
        return result.toString();
    }

    private static String readableAttachments(JsonArray attachments, int depth) {
        StringBuilder result = new StringBuilder();
        String indent = indent(depth);
        if (attachments.isEmpty()) return indent + "暂无附件\n";
        int visible = 0;
        for (JsonElement element : attachments) {
            if (!element.isJsonObject()) continue;
            JsonObject attachment = element.getAsJsonObject();
            visible++;
            String type = mediaTypeLabel(Jsons.string(attachment, "media_type"), Jsons.string(attachment, "mime_type"));
            String name = Jsons.string(attachment, "file_name");
            if (name.isEmpty()) name = Jsons.string(attachment, "original_name");
            result.append(indent).append("第 ").append(visible).append(" 项：").append(type);
            if (!name.isEmpty()) result.append(" · ").append(name);
            long size = Jsons.longValue(attachment, "size_bytes");
            if (size > 0) result.append(" · ").append(sizeText(size));
            result.append('\n');
        }
        return visible == 0 ? indent + "附件内容由文件权限保护\n" : result.toString();
    }

    private static String mediaTypeLabel(String type, String mime) {
        String value = type == null ? "" : type.trim().toLowerCase(java.util.Locale.ROOT);
        String mimeValue = mime == null ? "" : mime.trim().toLowerCase(java.util.Locale.ROOT);
        if ("image".equals(value) || "gif".equals(value) || mimeValue.startsWith("image/")) return "图片";
        if ("sticker".equals(value)) return "表情包";
        if ("video".equals(value) || mimeValue.startsWith("video/")) return "视频";
        if ("audio".equals(value) || mimeValue.startsWith("audio/")) return "音频";
        return "文件";
    }

    private static String sizeText(long bytes) {
        if (bytes < 1024) return bytes + " B";
        if (bytes < 1024L * 1024L) return String.format(java.util.Locale.CHINA, "%.1f KB", bytes / 1024f);
        if (bytes < 1024L * 1024L * 1024L) return String.format(java.util.Locale.CHINA, "%.1f MB", bytes / 1024f / 1024f);
        return String.format(java.util.Locale.CHINA, "%.2f GB", bytes / 1024f / 1024f / 1024f);
    }

    private static boolean isMediaCollection(String key) {
        return "attachments".equals(key) || "media_attachments".equals(key);
    }

    static List<Map.Entry<String, JsonElement>> visibleEntries(JsonObject object) {
        List<Map.Entry<String, JsonElement>> directEntries = new ArrayList<>();
        Map<String, Map.Entry<String, JsonElement>> preferredAliases = new LinkedHashMap<>();
        if (object == null) return directEntries;
        for (Map.Entry<String, JsonElement> entry : object.entrySet()) {
            if (!isVisibleField(object, entry.getKey())) continue;
            String alias = aliasGroup(entry.getKey());
            if (alias.isEmpty()) {
                directEntries.add(entry);
                continue;
            }
            Map.Entry<String, JsonElement> current = preferredAliases.get(alias);
            if (current == null || aliasPreference(entry.getKey()) < aliasPreference(current.getKey())) {
                preferredAliases.put(alias, entry);
            }
        }
        List<Map.Entry<String, JsonElement>> entries = new ArrayList<>(directEntries);
        entries.addAll(preferredAliases.values());
        entries.sort((left, right) -> {
            int priority = Integer.compare(
                fieldPriority(left.getKey(), normalize(left.getValue())),
                fieldPriority(right.getKey(), normalize(right.getValue()))
            );
            if (priority != 0) return priority;
            return Integer.compare(aliasPreference(left.getKey()), aliasPreference(right.getKey()));
        });
        return entries;
    }

    private static String aliasGroup(String key) {
        String normalized = key == null ? "" : key.trim().toLowerCase(Locale.ROOT);
        if (Arrays.asList("status_text", "status_name", "status").contains(normalized)) return "status";
        if (Arrays.asList("actor_name", "nickname", "user_name").contains(normalized)) return "actor";
        if (Arrays.asList("reason", "audit_reason", "review_remark").contains(normalized)) return "reason";
        if (Arrays.asList("balance", "reward_balance").contains(normalized)) return "balance";
        if (Arrays.asList("quantity", "count").contains(normalized)) return "quantity";
        if (Arrays.asList("room_name", "group_name").contains(normalized)) return "room";
        if (Arrays.asList("bounty_title", "task_title").contains(normalized)) return "bounty";
        if (Arrays.asList("product_name", "goods_name").contains(normalized)) return "product";
        return "";
    }

    private static int aliasPreference(String key) {
        String normalized = key == null ? "" : key.trim().toLowerCase(Locale.ROOT);
        switch (normalized) {
            case "status_text": case "actor_name": case "reason": case "balance":
            case "quantity": case "room_name": case "bounty_title": case "product_name":
                return 0;
            case "status_name": case "nickname": case "audit_reason": case "reward_balance":
            case "count": case "group_name": case "task_title": case "goods_name":
                return 1;
            default:
                return 2;
        }
    }

    /**
     * Shared business-field policy for user and management surfaces. API relation keys are useful
     * for requests, but they are not meaningful detail rows and must never leak into visible cards.
     */
    static boolean isVisibleField(JsonObject object, String key) {
        if (key == null || isHiddenField(key)) return false;
        String normalized = key.trim().toLowerCase(Locale.ROOT);
        if (isInternalRelationField(normalized) || isPagingField(normalized)
            || isControlField(normalized) || hasReadableCompanion(object, normalized)) return false;
        JsonElement value = object == null ? null : normalize(object.get(key));
        return isMeaningfulValue(value, normalized);
    }

    private static boolean isInternalRelationField(String key) {
        if ("id".equals(key) || key.endsWith("_ids")) return true;
        if (!key.endsWith("_id")) return false;
        return !"bundle_id".equals(key);
    }

    private static boolean isPagingField(String key) {
        return "pagination".equals(key) || "page".equals(key) || "limit".equals(key)
            || "offset".equals(key) || "per_page".equals(key) || "current_page".equals(key)
            || "total_pages".equals(key) || "next_page".equals(key) || "prev_page".equals(key)
            || "links".equals(key) || "meta".equals(key);
    }

    private static boolean isControlField(String key) {
        return key.startsWith("can_") || "action_url".equals(key) || "action_path".equals(key)
            || "api_path".equals(key) || "endpoint".equals(key) || "callback_url".equals(key)
            || "redirect_url".equals(key) || "deeplink".equals(key) || "deep_link".equals(key);
    }

    private static boolean hasReadableCompanion(JsonObject object, String key) {
        if (object == null) return false;
        String companion;
        switch (key) {
            case "status": companion = "status_text"; break;
            case "join_mode": companion = "join_mode_text"; break;
            case "file_category": companion = "file_category_name"; break;
            case "direction": companion = "direction_name"; break;
            case "amount": companion = "amount_text"; break;
            case "category": companion = "category_name"; break;
            case "source": companion = "source_name"; break;
            case "scope": companion = "scope_name"; break;
            case "type": companion = "type_text"; break;
            default: return false;
        }
        return object.has(companion) && isMeaningfulValue(normalize(object.get(companion)), companion);
    }

    private static boolean isMeaningfulValue(JsonElement value, String key) {
        if (value == null || value.isJsonNull()) return false;
        value = normalize(value);
        if (value == null || value.isJsonNull()) return false;
        if (value.isJsonPrimitive()) {
            if (!value.getAsJsonPrimitive().isString()) return true;
            String text = value.getAsString().trim();
            return !text.isEmpty() && !"-".equals(text) && !"null".equalsIgnoreCase(text);
        }
        if (value.isJsonObject()) return !visibleEntries(value.getAsJsonObject()).isEmpty();
        JsonArray array = value.getAsJsonArray();
        if (array.isEmpty()) return false;
        if (isMediaCollection(key)) return true;
        for (JsonElement child : array) {
            if (isMeaningfulValue(child, key)) return true;
        }
        return false;
    }

    private static int fieldPriority(String key, JsonElement value) {
        String normalized = key == null ? "" : key.trim().toLowerCase(Locale.ROOT);
        if (normalized.equals("title") || normalized.equals("name") || normalized.equals("nickname")
            || normalized.equals("remark") || normalized.equals("account_name")
            || normalized.equals("account") || normalized.equals("conversation_name")
            || normalized.equals("scope_name") || normalized.equals("product_name")
            || normalized.equals("goods_name") || normalized.equals("file_name")
            || normalized.equals("order_no") || normalized.equals("uid")
            || normalized.equals("public_no") || normalized.equals("group_number")) return 0;
        if (normalized.equals("status") || normalized.endsWith("_status")
            || normalized.endsWith("_type") || normalized.equals("type")
            || normalized.equals("role") || normalized.equals("current_role")
            || normalized.contains("category") || normalized.equals("tags")) return 10;
        if (normalized.contains("amount") || normalized.contains("balance")
            || normalized.contains("price") || normalized.endsWith("_count")
            || normalized.equals("quantity") || normalized.equals("stock")) return 20;
        if (normalized.equals("snapshot") || normalized.equals("payload")
            || normalized.equals("data") || normalized.equals("detail")) return 25;
        if (normalized.equals("content") || normalized.equals("description")
            || normalized.equals("message") || normalized.equals("reason")
            || normalized.equals("reply") || normalized.equals("summary")
            || normalized.equals("announcement")) return 30;
        if (normalized.endsWith("_at") || normalized.endsWith("_time")
            || normalized.endsWith("_date")) return 40;
        if (isMediaCollection(normalized) || (value != null && (value.isJsonArray() || value.isJsonObject()))) return 50;
        return 35;
    }

    static boolean isHiddenField(String key) {
        if (key == null) return true;
        String normalized = key.trim().toLowerCase(Locale.ROOT);
        if (normalized.equals("password") || normalized.equals("password_hash")
            || normalized.equals("salt") || normalized.equals("secret")
            || normalized.equals("api_secret") || normalized.equals("api_key")
            || normalized.equals("token") || normalized.equals("access_token")
            || normalized.equals("refresh_token") || normalized.equals("session_key")
            || normalized.equals("device_token") || normalized.equals("idempotency_key")
            || normalized.equals("authorization") || normalized.equals("cookie")
            || normalized.equals("raw_response") || normalized.equals("raw_body")
            || normalized.equals("request_body") || normalized.equals("response_body")
            || normalized.equals("payload_raw")
            || normalized.equals("request_headers") || normalized.equals("response_headers")
            || normalized.equals("stack") || normalized.equals("stack_trace")
            || normalized.equals("trace") || normalized.equals("exception")
            || normalized.equals("sql") || normalized.equals("sql_state")
            || normalized.equals("debug") || normalized.equals("internal_path")
            || normalized.equals("handler") || normalized.equals("controller")
            || normalized.equals("route") || normalized.equals("http_method")
            || normalized.equals("http_code")
            || normalized.equals("anonymity_scope") || normalized.equals("identity_view")
            || normalized.equals("audit_identity_visible") || normalized.equals("snapshot_read_only")
            || normalized.equals("embedded") || normalized.equals("read_only")
            || normalized.equals("source_context_hidden") || normalized.equals("nested_unavailable")) return true;
        return isPrivateMediaField(normalized);
    }

    private static boolean isPrivateMediaField(String key) {
        return "url".equals(key) || "file_url".equals(key) || "image_url".equals(key)
            || "thumbnail_url".equals(key) || "download_url".equals(key)
            || "storage_path".equals(key) || "file_path".equals(key) || "path".equals(key)
            || "original_file_url".equals(key) || "optimized_file_url".equals(key);
    }

    static String safeFieldValue(String key, JsonElement value) {
        String visible = DisplayText.fieldValue(key, value);
        if (visible == null || visible.trim().isEmpty()) return "-";
        String lower = visible.toLowerCase(Locale.ROOT);
        boolean technical = lower.contains("php warning") || lower.contains("php notice")
            || lower.contains("stack trace") || lower.contains("caused by:")
            || lower.contains("sqlstate[") || lower.contains("/www/wwwroot")
            || lower.matches("(?s).*(?:java|android)\\.[a-z0-9_.$]+(?:exception|error).*?");
        if (!technical) return visible;
        return ApiVisibleMessage.visible(visible, 500, 500);
    }

    private static String arrayItemTitle(String key, JsonElement child, int index) {
        String normalized = key == null ? "" : key.trim().toLowerCase(Locale.ROOT);
        String prefix;
        switch (normalized) {
            case "options": prefix = "选项"; break;
            case "claims": prefix = "领取记录"; break;
            case "returns": prefix = "退回记录"; break;
            case "comments": prefix = "评论"; break;
            case "replies": prefix = "回复"; break;
            case "recipients": prefix = "接收对象"; break;
            case "participants": case "members": prefix = "参与用户"; break;
            case "items": prefix = "内容"; break;
            default: prefix = "第"; break;
        }
        String suffix = "";
        if (child != null && child.isJsonObject()) {
            suffix = DisplayText.first(child.getAsJsonObject(), Arrays.asList(
                "option_text", "option_name", "product_name", "goods_name", "order_no",
                "title", "nickname", "account_name", "account", "name", "file_name", "original_name"
            ));
        } else if (child != null && child.isJsonPrimitive()) {
            suffix = DisplayText.value(child);
        }
        String title = "第".equals(prefix)
            ? "第 " + (index + 1) + " 项"
            : prefix + " " + (index + 1);
        return suffix.isEmpty() || "-".equals(suffix) ? title : title + " · " + suffix;
    }

    private static int dp(Context context, int value) {
        return Math.round(value * context.getResources().getDisplayMetrics().density);
    }

    private static String indent(int depth) {
        StringBuilder value = new StringBuilder();
        for (int index = 0; index < depth; index++) value.append("  ");
        return value.toString();
    }
}
