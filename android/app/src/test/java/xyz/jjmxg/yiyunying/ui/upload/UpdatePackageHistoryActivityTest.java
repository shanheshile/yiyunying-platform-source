package xyz.jjmxg.yiyunying.ui.upload;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertNotNull;
import static org.junit.Assert.assertTrue;

import android.content.ComponentName;
import android.content.Context;
import android.content.Intent;
import android.content.pm.ActivityInfo;
import android.content.res.XmlResourceParser;

import androidx.test.core.app.ApplicationProvider;

import org.junit.After;
import org.junit.Before;
import org.junit.Test;
import org.junit.runner.RunWith;
import org.robolectric.Robolectric;
import org.robolectric.RobolectricTestRunner;
import org.robolectric.android.controller.ActivityController;
import org.robolectric.annotation.Config;
import org.xmlpull.v1.XmlPullParser;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.YiyunyingApplication;
import xyz.jjmxg.yiyunying.data.update.UpdatePackageStore;
import xyz.jjmxg.yiyunying.service.UpdateInstallCleanupReceiver;
import xyz.jjmxg.yiyunying.ui.settings.UserSettingsActivity;

@RunWith(RobolectricTestRunner.class)
@Config(sdk = 34, application = YiyunyingApplication.class)
public final class UpdatePackageHistoryActivityTest {
    private Context context;

    @Before public void setUp() {
        context = ApplicationProvider.getApplicationContext();
        UpdatePackageStore.deleteAll(context);
        context.getSharedPreferences("update_packages_v1", Context.MODE_PRIVATE).edit().clear().commit();
    }

    @After public void tearDown() {
        UpdatePackageStore.deleteAll(context);
        context.getSharedPreferences("update_packages_v1", Context.MODE_PRIVATE).edit().clear().commit();
    }

    @Test public void historyIntentTargetsDedicatedSafeActivityAndUiHasBatchControls() {
        Intent intent = UpdatePackageHistoryActivity.intent(context,
            "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa");
        assertEquals(UpdatePackageHistoryActivity.class.getName(), intent.getComponent().getClassName());

        try (ActivityController<UpdatePackageHistoryActivity> controller =
                 Robolectric.buildActivity(UpdatePackageHistoryActivity.class, intent).setup()) {
            UpdatePackageHistoryActivity activity = controller.get();
            assertFalse(activity.isFinishing());
            assertNotNull(activity.findViewById(android.R.id.list));
            assertNotNull(activity.findViewById(android.R.id.empty));
            assertNotNull(activity.findViewById(android.R.id.button1));
        }
    }

    @Test public void settingsExposeHistoryAndAutoDeletePreference() {
        try (ActivityController<UserSettingsActivity> controller =
                 Robolectric.buildActivity(UserSettingsActivity.class).setup()) {
            UserSettingsActivity activity = controller.get();
            assertNotNull(activity.findViewById(R.id.autoDeleteUpdatePackages));
            assertNotNull(activity.findViewById(R.id.updatePackageHistoryButton));
            assertNotNull(activity.findViewById(R.id.updatePackageHistorySummary));
        }
    }

    @Test public void cleanupReceiverIsPrivateAndProviderOnlyExposesDedicatedFilesPath() throws Exception {
        ActivityInfo receiver = context.getPackageManager().getReceiverInfo(
            new ComponentName(context, UpdateInstallCleanupReceiver.class), 0);
        assertFalse(receiver.exported);

        boolean found = false;
        try (XmlResourceParser parser = context.getResources().getXml(R.xml.capture_file_paths)) {
            int event;
            while ((event = parser.next()) != XmlPullParser.END_DOCUMENT) {
                if (event != XmlPullParser.START_TAG || !"files-path".equals(parser.getName())) continue;
                String name = parser.getAttributeValue(null, "name");
                String path = parser.getAttributeValue(null, "path");
                if ("update_packages".equals(name) && "update_packages/".equals(path)) found = true;
            }
        }
        assertTrue(found);
    }
}
