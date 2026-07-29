package xyz.jjmxg.yiyunying.ui.notification;

import android.app.Activity;
import android.content.ClipData;
import android.content.ClipboardManager;
import android.content.Context;
import android.content.ContextWrapper;
import android.graphics.Typeface;
import android.view.Gravity;
import android.view.View;
import android.widget.FrameLayout;
import android.widget.LinearLayout;
import android.widget.ScrollView;
import android.widget.TextView;
import android.widget.Toast;

import androidx.annotation.Nullable;
import androidx.appcompat.app.AlertDialog;

import com.google.android.material.card.MaterialCardView;
import xyz.jjmxg.yiyunying.ui.common.YiyunyingDialogBuilder;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;

import java.util.concurrent.atomic.AtomicBoolean;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.core.CrashReporter;
import xyz.jjmxg.yiyunying.core.RuntimeLanguage;
import xyz.jjmxg.yiyunying.data.api.ApiVisibleMessage;
import xyz.jjmxg.yiyunying.data.api.Jsons;

/** Presents a notification as user-facing content instead of exposing its JSON payload. */
final class NotificationDetailDialog {
    private static final RelatedField[] RELATED_FIELDS = {
        field("发起人", true, false, "actor_name", "nickname", "user_name"),
        field("当前状态", false, true, "status_name", "status"),
        field("处理说明", true, false, "reason", "audit_reason", "review_remark"),
        field("评论内容", true, false, "comment_content"),
        field("回复内容", true, false, "reply_content"),
        field("金额", false, false, "amount"),
        field("余额变动", false, false, "balance", "reward_balance"),
        field("礼物", true, false, "gift_name"),
        field("数量", false, false, "quantity", "count"),
        field("群聊/聊天室", true, false, "room_name", "group_name"),
        field("帖子", true, false, "post_title"),
        field("悬赏", true, false, "bounty_title", "task_title"),
        field("资源", true, false, "resource_title"),
        field("商品", true, false, "product_name", "goods_name"),
        field("订单号", true, false, "order_no"),
        field("版本", false, false, "version_name"),
        field("维护开始", false, false, "maintenance_start_at"),
        field("维护结束", false, false, "maintenance_end_at"),
        field("生效时间", false, false, "effective_at"),
        field("有效期至", false, false, "expired_at")
    };

    private NotificationDetailDialog() { }

    @Nullable
    static AlertDialog show(Context context, JsonObject item, String actionLabel, Runnable action) {
        Activity activity = findActivity(context);
        if (activity == null || activity.isFinishing() || activity.isDestroyed()) return null;
        JsonObject notification = item == null ? new JsonObject() : item.deepCopy();

        ScrollView scroll = new ScrollView(context);
        LinearLayout root = new LinearLayout(context);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setPadding(dp(context, 20), dp(context, 4), dp(context, 20), dp(context, 18));

        JsonObject payload = payload(notification);

        TextView category = new TextView(context);
        String categoryValue = notificationCategory(notification, payload);
        category.setText(tr(context, categoryValue));
        category.setTextColor(xyz.jjmxg.yiyunying.ui.common.ThemeColors.primary(context));
        category.setTextAppearance(com.google.android.material.R.style.TextAppearance_Material3_LabelLarge);
        category.setGravity(Gravity.CENTER_VERTICAL);
        category.setTypeface(Typeface.DEFAULT, Typeface.BOLD);
        category.setBackgroundResource(R.drawable.bg_notification_badge);
        category.setPadding(dp(context, 10), dp(context, 5), dp(context, 10), dp(context, 5));
        LinearLayout.LayoutParams categoryParams = new LinearLayout.LayoutParams(-2, -2);
        categoryParams.bottomMargin = dp(context, 14);
        root.addView(category, categoryParams);

        TextView title = new TextView(context);
        setServerText(context, title, value(notification, "title"), "通知详情");
        title.setTextColor(context.getColor(R.color.on_surface));
        title.setTextAppearance(com.google.android.material.R.style.TextAppearance_Material3_TitleLarge);
        title.setTypeface(Typeface.DEFAULT, Typeface.BOLD);
        root.addView(title, new LinearLayout.LayoutParams(-1, -2));

        TextView content = new TextView(context);
        setServerText(context, content, value(notification, "content"), "暂无通知正文");
        content.setTextColor(context.getColor(R.color.on_surface));
        content.setTextAppearance(com.google.android.material.R.style.TextAppearance_Material3_BodyLarge);
        content.setTextIsSelectable(true);
        content.setLineSpacing(0, 1.15f);
        LinearLayout.LayoutParams contentParams = new LinearLayout.LayoutParams(-1, -2);
        contentParams.topMargin = dp(context, 12);
        contentParams.bottomMargin = dp(context, 16);
        root.addView(content, contentParams);

        addMeta(context, root, "通知时间", fallback(value(notification, "created_at"), "未知"), true);
        addMeta(context, root, "阅读状态", booleanValue(notification, "is_read") ? "已读" : "未读", false);
        addMeta(context, root, "通知类型", categoryValue, false);

        LinearLayout related = buildRelated(context, payload);
        if (related.getChildCount() > 0) {
            TextView heading = new TextView(context);
            heading.setText(tr(context, "相关内容"));
            heading.setTextColor(context.getColor(R.color.on_surface));
            heading.setTextAppearance(com.google.android.material.R.style.TextAppearance_Material3_TitleSmall);
            LinearLayout.LayoutParams headingParams = new LinearLayout.LayoutParams(-1, -2);
            headingParams.topMargin = dp(context, 18);
            headingParams.bottomMargin = dp(context, 8);
            root.addView(heading, headingParams);

            MaterialCardView card = new MaterialCardView(context);
            card.setCardElevation(0);
            card.setRadius(dp(context, 6));
            card.setCardBackgroundColor(context.getColor(R.color.surface_container));
            card.addView(related, new FrameLayout.LayoutParams(-1, -2));
            root.addView(card, new LinearLayout.LayoutParams(-1, -2));
        }

        scroll.addView(root, new ScrollView.LayoutParams(-1, -2));
        String copyText = ApiVisibleMessage.visibleContent(value(notification, "title"), "通知") + "\n"
            + ApiVisibleMessage.visibleContent(value(notification, "content"), "") + "\n"
            + fallback(value(notification, "created_at"), "");
        com.google.android.material.dialog.MaterialAlertDialogBuilder builder = new YiyunyingDialogBuilder(context)
            .setTitle(tr(context, "通知详情"))
            .setView(scroll)
            .setNeutralButton(tr(context, "复制"), (dialog, which) -> copy(context, copyText));
        AtomicBoolean actionHandled = new AtomicBoolean(false);
        if (action == null) {
            builder.setPositiveButton(tr(context, "关闭"), null);
        } else {
            builder.setNegativeButton(tr(context, "取消"), null)
                .setPositiveButton(
                    tr(context, actionLabel == null || actionLabel.trim().isEmpty()
                        ? "查看相关内容" : actionLabel),
                    (dialog, which) -> {
                        if (!actionHandled.compareAndSet(false, true)
                            || activity.isFinishing() || activity.isDestroyed()) {
                            return;
                        }
                        try {
                            action.run();
                        } catch (RuntimeException | LinkageError exception) {
                            CrashReporter.record("执行通知详情操作", exception);
                            Toast.makeText(activity, tr(activity, "相关页面暂时无法打开，请重试"),
                                Toast.LENGTH_LONG).show();
                        }
                    });
        }
        try {
            AlertDialog dialog = (AlertDialog) builder.create();
            dialog.setCanceledOnTouchOutside(true);
            dialog.show();
            return dialog;
        } catch (RuntimeException | LinkageError exception) {
            CrashReporter.record("显示通知详情", exception);
            return null;
        }
    }

    static JsonObject payload(JsonObject item) {
        if (item == null) return new JsonObject();
        JsonElement direct = item.get("data");
        if (direct != null && direct.isJsonObject()) return nestedPayload(direct.getAsJsonObject());
        JsonElement payload = item.get("payload");
        if (payload != null && payload.isJsonObject()) return payload.getAsJsonObject().deepCopy();
        String raw = fallback(value(item, "data_json"), value(item, "payload_json"));
        if (raw.isEmpty()) return new JsonObject();
        try {
            JsonElement parsed = Jsons.parse(raw);
            return parsed != null && parsed.isJsonObject()
                ? nestedPayload(parsed.getAsJsonObject()) : new JsonObject();
        } catch (RuntimeException ignored) {
            return new JsonObject();
        }
    }

    private static JsonObject nestedPayload(JsonObject object) {
        if (object == null) return new JsonObject();
        JsonElement nested = object.get("payload");
        return nested != null && nested.isJsonObject()
            ? nested.getAsJsonObject().deepCopy() : object.deepCopy();
    }

    static String notificationCategory(JsonObject item, JsonObject payload) {
        String source = (value(item, "type") + " "
            + value(item, "notification_type") + " "
            + value(item, "group_name") + " "
            + value(item, "center_name") + " "
            + value(item, "title") + " "
            + value(payload, "type") + " "
            + value(payload, "notification_type") + " "
            + value(payload, "target_type")).toLowerCase();
        if (containsAny(source, "maintenance", "update", "system", "announcement", "notice", "维护", "更新", "公告", "系统", "客服")) {
            return "系统通知";
        }
        if (containsAny(source, "like", "follow", "favorite", "comment", "reply", "moment", "dynamic", "点赞", "关注", "收藏", "评论", "回复", "动态")) {
            return "动态互动";
        }
        if (containsAny(source, "forum", "post", "thread", "论坛", "帖子")) {
            return "论坛通知";
        }
        if (containsAny(source, "bounty", "task", "悬赏", "任务")) {
            return "悬赏通知";
        }
        if (containsAny(source, "friend", "relation", "invitation", "join_request", "group_request", "好友", "邀请", "入群", "群聊申请")) {
            return "好友与群聊通知";
        }
        if (containsAny(source, "red_packet", "transfer", "gift", "order", "payment", "balance", "store", "product", "红包", "转账", "礼物", "订单", "支付", "余额", "商品")) {
            return "资产与订单通知";
        }
        if (containsAny(source, "message", "chat", "room", "mention", "私聊", "聊天室", "消息", "@")) {
            return "聊天互动通知";
        }
        if (containsAny(source, "lottery", "vote", "activity", "抽奖", "投票", "活动")) {
            return "活动通知";
        }
        String groupName = value(item, "group_name");
        String centerName = value(item, "center_name");
        return fallback(groupName.isEmpty() ? centerName : groupName, "系统通知");
    }

    private static boolean containsAny(String value, String... needles) {
        for (String needle : needles) {
            if (value.contains(needle)) return true;
        }
        return false;
    }

    private static LinearLayout buildRelated(Context context, JsonObject payload) {
        LinearLayout result = new LinearLayout(context);
        result.setOrientation(LinearLayout.VERTICAL);
        result.setPadding(dp(context, 14), dp(context, 6), dp(context, 14), dp(context, 6));
        for (RelatedField field : RELATED_FIELDS) {
            FieldValue match = firstValue(payload, field.keys);
            if (match == null) continue;
            String display = field.status ? localized(match.key, match.value) : match.value;
            addMeta(context, result, field.label, display, field.dynamic);
        }
        return result;
    }

    private static RelatedField field(String label, boolean dynamic, boolean status,
                                      String... keys) {
        return new RelatedField(label, dynamic, status, keys);
    }

    private static FieldValue firstValue(JsonObject payload, String[] keys) {
        for (String key : keys) {
            String candidate = value(payload, key);
            if (!candidate.isEmpty()) return new FieldValue(key, candidate);
        }
        return null;
    }

    private static String localized(String key, String value) {
        if (!"status".equals(key) && !"status_name".equals(key)) return value;
        switch (value.toLowerCase()) {
            case "pending": return "待处理";
            case "approved": case "approve": return "已通过";
            case "rejected": case "reject": return "未通过";
            case "completed": return "已完成";
            case "cancelled": case "canceled": return "已取消";
            case "expired": return "已过期";
            case "active": return "进行中";
            default: return value;
        }
    }

    private static void addMeta(Context context, LinearLayout parent, String label, String value, boolean dynamic) {
        if (value == null || value.trim().isEmpty()) return;
        LinearLayout row = new LinearLayout(context);
        row.setOrientation(LinearLayout.HORIZONTAL);
        row.setGravity(Gravity.TOP);
        row.setPadding(0, dp(context, 7), 0, dp(context, 7));
        TextView key = new TextView(context);
        key.setText(tr(context, label));
        key.setTextColor(context.getColor(R.color.on_surface_variant));
        key.setTextAppearance(com.google.android.material.R.style.TextAppearance_Material3_LabelLarge);
        row.addView(key, new LinearLayout.LayoutParams(dp(context, 92), -2));
        TextView text = new TextView(context);
        if (dynamic) RuntimeLanguage.setDynamicText(text, value);
        else text.setText(tr(context, value));
        text.setTextColor(context.getColor(R.color.on_surface));
        text.setTextAppearance(com.google.android.material.R.style.TextAppearance_Material3_BodyMedium);
        text.setTextIsSelectable(true);
        row.addView(text, new LinearLayout.LayoutParams(0, -2, 1f));
        parent.addView(row, new LinearLayout.LayoutParams(-1, -2));
    }

    private static void setServerText(Context context, TextView view, String value, String fallback) {
        String visible = ApiVisibleMessage.visibleContent(value, fallback);
        if (visible.equals(fallback)) view.setText(tr(context, fallback));
        else RuntimeLanguage.setDynamicText(view, visible);
    }

    private static String value(JsonObject object, String key) {
        try {
            JsonElement value = object == null ? null : object.get(key);
            return value == null || value.isJsonNull() || !value.isJsonPrimitive() ? "" : value.getAsString().trim();
        } catch (RuntimeException ignored) {
            return "";
        }
    }

    private static boolean booleanValue(JsonObject object, String key) {
        try { return object != null && object.has(key) && object.get(key).getAsBoolean(); }
        catch (RuntimeException ignored) { return false; }
    }

    private static String fallback(String value, String fallback) {
        return value == null || value.trim().isEmpty() ? fallback : value;
    }

    private static void copy(Context context, String value) {
        ClipboardManager manager = (ClipboardManager) context.getSystemService(Context.CLIPBOARD_SERVICE);
        if (manager != null) manager.setPrimaryClip(ClipData.newPlainText(tr(context, "通知详情"), value));
    }

    private static CharSequence tr(Context context, String value) {
        return RuntimeLanguage.translate(context, value);
    }

    @Nullable
    private static Activity findActivity(Context context) {
        Context current = context;
        while (current instanceof ContextWrapper) {
            if (current instanceof Activity) return (Activity) current;
            Context base = ((ContextWrapper) current).getBaseContext();
            if (base == current) break;
            current = base;
        }
        return current instanceof Activity ? (Activity) current : null;
    }

    private static int dp(Context context, int value) {
        return Math.round(value * context.getResources().getDisplayMetrics().density);
    }

    private static final class RelatedField {
        final String label;
        final boolean dynamic;
        final boolean status;
        final String[] keys;

        RelatedField(String label, boolean dynamic, boolean status, String[] keys) {
            this.label = label;
            this.dynamic = dynamic;
            this.status = status;
            this.keys = keys;
        }
    }

    private static final class FieldValue {
        final String key;
        final String value;

        FieldValue(String key, String value) {
            this.key = key;
            this.value = value;
        }
    }
}
