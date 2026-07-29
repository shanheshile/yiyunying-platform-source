package xyz.jjmxg.yiyunying.ui.notification;

import com.google.gson.JsonObject;

import org.junit.Test;

import static org.junit.Assert.assertEquals;

public final class NotificationDetailDialogTest {
    @Test public void classifiesSystemAndDynamicNotifications() {
        assertEquals("系统通知", category("maintenance", "服务器维护"));
        assertEquals("系统通知", category("app_update", "发现新版本"));
        assertEquals("动态互动", category("post_like", "有人赞了你的动态"));
        assertEquals("动态互动", category("comment_reply", "有人回复了你的评论"));
    }

    @Test public void classifiesForumBountyRelationshipAndAssets() {
        assertEquals("论坛通知", category("forum_post", "帖子审核完成"));
        assertEquals("悬赏通知", category("bounty_status", "悬赏已完成"));
        assertEquals("好友与群聊通知", category("friend_request", "新的好友申请"));
        assertEquals("好友与群聊通知", category("group_invitation", "邀请你加入群聊"));
        assertEquals("资产与订单通知", category("order_paid", "订单支付成功"));
    }

    @Test public void preservesNamedServerCategoryWhenTypeIsUnknown() {
        JsonObject item = new JsonObject();
        item.addProperty("group_name", "审核中心");
        item.addProperty("type", "custom_review");
        assertEquals("审核中心", NotificationDetailDialog.notificationCategory(item, new JsonObject()));
    }

    private static String category(String type, String title) {
        JsonObject item = new JsonObject();
        item.addProperty("type", type);
        item.addProperty("title", title);
        return NotificationDetailDialog.notificationCategory(item, new JsonObject());
    }
}
