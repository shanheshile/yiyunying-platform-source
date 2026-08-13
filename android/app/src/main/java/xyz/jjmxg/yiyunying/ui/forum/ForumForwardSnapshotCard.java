package xyz.jjmxg.yiyunying.ui.forum;

import android.content.Context;
import android.graphics.drawable.GradientDrawable;
import android.text.TextUtils;
import android.view.View;
import android.view.ViewGroup;
import android.widget.LinearLayout;
import android.widget.TextView;

import androidx.core.content.ContextCompat;

import com.google.android.material.card.MaterialCardView;
import com.google.gson.JsonArray;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;

import java.util.ArrayList;
import java.util.List;

import xyz.jjmxg.yiyunying.R;

/** Renders detached forum snapshots without links back to private/group chat. */
public final class ForumForwardSnapshotCard {
    private ForumForwardSnapshotCard() { }

    public static boolean renderInto(
        Context context,
        LinearLayout container,
        JsonObject bundle,
        String legacyText
    ) {
        container.removeAllViews();
        if (!shouldRender(bundle, legacyText)) {
            container.setVisibility(View.GONE);
            return false;
        }
        container.addView(create(context, bundle, legacyText), new LinearLayout.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));
        container.setVisibility(View.VISIBLE);
        return true;
    }

    public static View create(Context context, JsonObject bundle, String legacyText) {
        JsonObject safeBundle = bundle == null ? new JsonObject() : bundle;
        MaterialCardView card = new MaterialCardView(context);
        card.setClickable(false);
        card.setFocusable(false);
        card.setRadius(dp(context, 16));
        card.setStrokeWidth(dp(context, 1));
        card.setStrokeColor(ContextCompat.getColor(context, R.color.outline_variant));
        card.setCardBackgroundColor(ContextCompat.getColor(context, R.color.surface_container));
        card.setContentDescription("只读聊天快照，不连接原会话");

        LinearLayout body = new LinearLayout(context);
        body.setOrientation(LinearLayout.VERTICAL);
        body.setPadding(dp(context, 14), dp(context, 13), dp(context, 14), dp(context, 13));

        TextView title = textView(context, 16f, R.color.on_surface, true);
        title.setText(sourceLabel(primitive(safeBundle, "source_kind")) + " · "
            + fallback(primitive(safeBundle, "title"), "聊天记录快照"));
        body.addView(title);

        TextView meta = textView(context, 12f, R.color.on_surface_variant, false);
        long count = positiveLong(safeBundle, "item_count");
        String countText = count > 0 ? count + " 条" : "旧版记录";
        meta.setText(countText + " · 只读副本 · 不提供原消息跳转");
        LinearLayout.LayoutParams metaParams = fullWidth();
        metaParams.topMargin = dp(context, 4);
        body.addView(meta, metaParams);

        JsonArray previews = array(safeBundle, "preview_items");
        if (previews.isEmpty()) {
            TextView legacy = textView(context, 14f, R.color.on_surface_variant, false);
            legacy.setText(fallback(cleanLegacySummary(legacyText), "旧版快照仅保留标题与数量，无法显示结构化内容。"));
            LinearLayout.LayoutParams legacyParams = fullWidth();
            legacyParams.topMargin = dp(context, 10);
            body.addView(legacy, legacyParams);
        } else {
            int shown = 0;
            for (JsonElement element : previews) {
                if (!element.isJsonObject() || shown >= 4) continue;
                LinearLayout item = previewItem(context, element.getAsJsonObject());
                LinearLayout.LayoutParams itemParams = fullWidth();
                itemParams.topMargin = dp(context, 9);
                body.addView(item, itemParams);
                shown++;
            }
            if (booleanValue(safeBundle, "preview_truncated")) {
                TextView more = textView(context, 12f, R.color.on_surface_variant, false);
                more.setText("其余消息未在帖子中展开");
                LinearLayout.LayoutParams moreParams = fullWidth();
                moreParams.topMargin = dp(context, 8);
                body.addView(more, moreParams);
            }
        }
        card.addView(body);
        return card;
    }

    static List<String> safeDisplayLines(JsonObject bundle, String legacyText) {
        JsonObject value = bundle == null ? new JsonObject() : bundle;
        List<String> lines = new ArrayList<>();
        lines.add(sourceLabel(primitive(value, "source_kind")));
        lines.add(fallback(primitive(value, "title"), cleanLegacySummary(legacyText)));
        for (JsonElement element : array(value, "preview_items")) {
            if (!element.isJsonObject() || lines.size() >= 18) continue;
            JsonObject item = element.getAsJsonObject();
            lines.add(primitive(item, "sender"));
            lines.add(primitive(item, "time"));
            lines.add(primitive(item, "content"));
            lines.add(primitive(item, "reference_summary"));
            lines.add(primitive(item, "nested_summary"));
            for (JsonElement attachmentElement : array(item, "attachments")) {
                if (!attachmentElement.isJsonObject()) continue;
                JsonObject attachment = attachmentElement.getAsJsonObject();
                lines.add(primitive(attachment, "label") + " " + primitive(attachment, "name"));
            }
        }
        lines.removeIf(String::isEmpty);
        return lines;
    }

    public static boolean isLegacyForwardSummary(String text) {
        String value = text == null ? "" : text.trim();
        return value.startsWith("【合并转发 ·") || value.startsWith("【匿名合并转发 ·");
    }

    private static boolean shouldRender(JsonObject bundle, String legacyText) {
        return (bundle != null && (!bundle.entrySet().isEmpty())
            && (positiveLong(bundle, "item_count") > 0 || !primitive(bundle, "title").isEmpty()))
            || isLegacyForwardSummary(legacyText);
    }

    private static LinearLayout previewItem(Context context, JsonObject item) {
        LinearLayout block = new LinearLayout(context);
        block.setOrientation(LinearLayout.VERTICAL);
        block.setPadding(dp(context, 11), dp(context, 9), dp(context, 11), dp(context, 9));
        GradientDrawable background = new GradientDrawable();
        background.setColor(ContextCompat.getColor(context, R.color.surface));
        background.setCornerRadius(dp(context, 12));
        background.setStroke(dp(context, 1), ContextCompat.getColor(context, R.color.outline_variant));
        block.setBackground(background);

        TextView sender = textView(context, 13f, R.color.on_surface, true);
        String time = primitive(item, "time");
        sender.setText(fallback(primitive(item, "sender"), "聊天成员")
            + (time.isEmpty() ? "" : " · " + time));
        block.addView(sender);

        TextView content = textView(context, 14f, R.color.on_surface, false);
        content.setText(fallback(primitive(item, "content"), "[消息]"));
        LinearLayout.LayoutParams contentParams = fullWidth();
        contentParams.topMargin = dp(context, 4);
        block.addView(content, contentParams);

        JsonArray attachments = array(item, "attachments");
        if (!attachments.isEmpty()) {
            List<String> summaries = new ArrayList<>();
            for (JsonElement element : attachments) {
                if (!element.isJsonObject() || summaries.size() >= 3) continue;
                JsonObject attachment = element.getAsJsonObject();
                String label = fallback(primitive(attachment, "label"), "附件");
                String name = primitive(attachment, "name");
                summaries.add("[" + label + "]" + (name.isEmpty() ? "" : " " + name));
            }
            if (!summaries.isEmpty()) {
                TextView files = textView(context, 12f, R.color.primary, false);
                files.setText(TextUtils.join("  ", summaries));
                LinearLayout.LayoutParams filesParams = fullWidth();
                filesParams.topMargin = dp(context, 5);
                block.addView(files, filesParams);
            }
        }

        String reference = primitive(item, "reference_summary");
        String nested = primitive(item, "nested_summary");
        if (!reference.isEmpty() || !nested.isEmpty()) {
            TextView contextLine = textView(context, 12f, R.color.on_surface_variant, false);
            contextLine.setText(TextUtils.join(" · ", nonEmpty(reference, nested)));
            LinearLayout.LayoutParams contextParams = fullWidth();
            contextParams.topMargin = dp(context, 5);
            block.addView(contextLine, contextParams);
        }
        return block;
    }

    private static TextView textView(Context context, float sizeSp, int color, boolean strong) {
        TextView view = new TextView(context);
        view.setTextSize(sizeSp);
        view.setTextColor(ContextCompat.getColor(context, color));
        view.setAutoLinkMask(0);
        view.setLinksClickable(false);
        view.setClickable(false);
        view.setFocusable(false);
        view.setTextIsSelectable(true);
        if (strong) view.setTypeface(view.getTypeface(), android.graphics.Typeface.BOLD);
        return view;
    }

    private static String primitive(JsonObject object, String key) {
        if (object == null || !object.has(key) || !object.get(key).isJsonPrimitive()) return "";
        try { return truncate(object.get(key).getAsString(), 260); }
        catch (RuntimeException ignored) { return ""; }
    }

    private static JsonArray array(JsonObject object, String key) {
        return object != null && object.has(key) && object.get(key).isJsonArray()
            ? object.getAsJsonArray(key) : new JsonArray();
    }

    private static long positiveLong(JsonObject object, String key) {
        if (object == null || !object.has(key) || !object.get(key).isJsonPrimitive()) return 0L;
        try { return Math.max(0L, object.get(key).getAsLong()); }
        catch (RuntimeException ignored) { return 0L; }
    }

    private static boolean booleanValue(JsonObject object, String key) {
        if (object == null || !object.has(key) || !object.get(key).isJsonPrimitive()) return false;
        try { return object.get(key).getAsBoolean(); }
        catch (RuntimeException ignored) { return false; }
    }

    private static String cleanLegacySummary(String value) {
        String text = value == null ? "" : value.trim();
        if (!isLegacyForwardSummary(text)) return "";
        int newline = text.indexOf('\n');
        return truncate(newline >= 0 ? text.substring(0, newline) : text, 120);
    }

    private static String sourceLabel(String kind) {
        if ("private".equals(kind)) return "私聊快照";
        if ("group".equals(kind)) return "群聊快照";
        if ("service".equals(kind)) return "客服会话快照";
        return "聊天记录快照";
    }

    private static String fallback(String value, String fallback) {
        return value == null || value.trim().isEmpty() ? (fallback == null ? "" : fallback) : value.trim();
    }

    private static String truncate(String value, int limit) {
        if (value == null) return "";
        String trimmed = value.trim();
        return trimmed.length() <= limit ? trimmed : trimmed.substring(0, limit) + "…";
    }

    private static List<String> nonEmpty(String first, String second) {
        List<String> values = new ArrayList<>();
        if (first != null && !first.isEmpty()) values.add(first);
        if (second != null && !second.isEmpty()) values.add(second);
        return values;
    }

    private static LinearLayout.LayoutParams fullWidth() {
        return new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT);
    }

    private static int dp(Context context, int value) {
        return Math.round(value * context.getResources().getDisplayMetrics().density);
    }
}
