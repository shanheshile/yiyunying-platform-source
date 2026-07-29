package xyz.jjmxg.yiyunying.ui.notification;

import com.google.gson.JsonObject;

import org.junit.Test;

import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;

public final class NotificationLifecycleRoutingTest {
    @Test
    public void updateAndMaintenanceNotificationsStayInTheirDetailSurface() {
        JsonObject update = item("app_update", "software_updates");
        JsonObject maintenance = item("maintenance", "system_maintenance");

        assertTrue(NotificationCenterFragment.isLifecycleNotification(update, new JsonObject()));
        assertTrue(NotificationCenterFragment.isLifecycleNotification(maintenance, new JsonObject()));
    }

    @Test
    public void ordinaryBusinessNotificationsCanStillNavigate() {
        JsonObject post = item("forum_post", "forums");
        JsonObject order = item("order_paid", "orders");

        assertFalse(NotificationCenterFragment.isLifecycleNotification(post, new JsonObject()));
        assertFalse(NotificationCenterFragment.isLifecycleNotification(order, new JsonObject()));
    }

    @Test
    public void nestedPayloadAlsoIdentifiesLifecycleNotification() {
        JsonObject item = item("system_notice", "system");
        JsonObject payload = new JsonObject();
        payload.addProperty("target_type", "version_update");

        assertTrue(NotificationCenterFragment.isLifecycleNotification(item, payload));
    }

    private static JsonObject item(String type, String group) {
        JsonObject item = new JsonObject();
        item.addProperty("notification_type", type);
        item.addProperty("group_key", group);
        return item;
    }
}
