package xyz.jjmxg.yiyunying.ui.notification;

import android.app.Activity;

import androidx.appcompat.app.AlertDialog;

import com.google.gson.JsonObject;

import org.junit.Test;
import org.junit.runner.RunWith;
import org.robolectric.Robolectric;
import org.robolectric.RobolectricTestRunner;
import org.robolectric.android.controller.ActivityController;
import org.robolectric.annotation.Config;

import xyz.jjmxg.yiyunying.R;

import static org.junit.Assert.assertNotNull;
import static org.junit.Assert.assertNull;
import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertTrue;

@RunWith(RobolectricTestRunner.class)
@Config(sdk = 35)
public final class NotificationDetailDialogUiTest {
    @Test public void nestedUpdatePayloadIsNormalizedWithoutMutatingSource() {
        JsonObject update = new JsonObject();
        update.addProperty("version_name", "2.6.33");
        JsonObject data = new JsonObject();
        data.add("payload", update);
        JsonObject notification = new JsonObject();
        notification.add("data", data);

        JsonObject normalized = NotificationDetailDialog.payload(notification);

        assertEquals("2.6.33", normalized.get("version_name").getAsString());
        normalized.addProperty("version_name", "changed");
        assertEquals("2.6.33",
            notification.getAsJsonObject("data")
                .getAsJsonObject("payload")
                .get("version_name").getAsString());
    }

    @Test public void securityLoginNotificationOpensAndDestroyedActivityIsIgnored() {
        ActivityController<Activity> controller = Robolectric.buildActivity(Activity.class);
        Activity activity = controller.get();
        activity.setTheme(R.style.Theme_Yiyunying);
        controller.setup();

        JsonObject payload = new JsonObject();
        payload.addProperty("target_type", "security_login");
        payload.addProperty("device", "Xiaomi 23127PN0CC / Android 16");
        payload.addProperty("ip", "127.0.0.1");

        JsonObject notification = new JsonObject();
        notification.addProperty("source_type", "business");
        notification.addProperty("source_id", 42);
        notification.addProperty("notification_type", "security_login");
        notification.addProperty("group_key", "other");
        notification.addProperty("title", "检测到新设备登录");
        notification.addProperty("content", "登录设备：Xiaomi 23127PN0CC / Android 16");
        notification.addProperty("created_at", "2026-07-23 16:20:00");
        notification.add("data", payload);

        AlertDialog dialog = NotificationDetailDialog.show(activity, notification, null, null);
        assertNotNull(dialog);
        assertTrue(dialog.isShowing());
        dialog.dismiss();

        controller.pause().stop().destroy();
        assertNull(NotificationDetailDialog.show(activity, notification, null, null));
    }
}
