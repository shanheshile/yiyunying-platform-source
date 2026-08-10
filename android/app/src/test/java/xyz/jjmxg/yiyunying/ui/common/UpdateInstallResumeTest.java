package xyz.jjmxg.yiyunying.ui.common;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertNotNull;
import static org.junit.Assert.assertTrue;

import android.app.Activity;
import android.content.Context;
import android.content.Intent;
import android.net.Uri;
import android.os.Bundle;
import android.widget.TextView;

import androidx.test.core.app.ApplicationProvider;

import com.google.gson.JsonObject;

import org.junit.After;
import org.junit.Test;
import org.junit.runner.RunWith;
import org.robolectric.Robolectric;
import org.robolectric.RobolectricTestRunner;
import org.robolectric.Shadows;
import org.robolectric.android.controller.ActivityController;
import org.robolectric.annotation.Config;

import java.io.File;
import java.io.FileOutputStream;

import xyz.jjmxg.yiyunying.BuildConfig;
import xyz.jjmxg.yiyunying.YiyunyingApplication;
import xyz.jjmxg.yiyunying.data.update.UpdatePackageStore;

@RunWith(RobolectricTestRunner.class)
@Config(sdk = 34, application = YiyunyingApplication.class)
public class UpdateInstallResumeTest {
    @After public void tearDown() {
        Context context = ApplicationProvider.getApplicationContext();
        UpdatePackageStore.deleteAll(context);
        context.getSharedPreferences("update_packages_v1", Context.MODE_PRIVATE).edit().clear().commit();
    }

    @Test public void returningWithPermissionAutomaticallyLaunchesExactStoredApk() throws Exception {
        try (ActivityController<ResumeActivity> controller =
                 Robolectric.buildActivity(ResumeActivity.class).setup().pause()) {
            ResumeActivity activity = controller.get();
            JsonObject update = new JsonObject();
            update.addProperty("package_name", activity.getPackageName());
            update.addProperty("version_name", "2.7.14");
            update.addProperty("version_code", BuildConfig.VERSION_CODE + 1L);
            update.addProperty("size_bytes", 4L);
            update.addProperty("sha256",
                "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa");
            UpdatePackageStore.Entry entry = UpdatePackageStore.prepare(
                activity, update, "https://example.test/app.apk");
            File apk = UpdatePackageStore.apkFile(activity, entry.id);
            try (FileOutputStream output = new FileOutputStream(apk, false)) {
                output.write(new byte[] { 1, 2, 3, 4 });
            }
            UpdatePackageStore.markComplete(activity, entry.id);
            UpdatePackageStore.markPermissionPending(activity, entry.id, false);
            assertNotNull(UpdatePackageStore.newestPermissionPending(activity));
            AppUpdateInstaller.dispatchInstall(activity, entry,
                Uri.parse("content://test/update.apk"), false, null);

            assertEquals(UpdatePackageStore.STATE_INSTALL_REQUESTED,
                UpdatePackageStore.find(activity, entry.id).state);
            Intent launched = Shadows.shadowOf((Activity) activity).getNextStartedActivity();
            assertNotNull(launched);
            assertEquals(Intent.ACTION_VIEW, launched.getAction());
            assertEquals("application/vnd.android.package-archive", launched.getType());
            assertEquals("content", launched.getData().getScheme());
            assertTrue((launched.getFlags() & Intent.FLAG_GRANT_READ_URI_PERMISSION) != 0);
        }
    }

    @Test public void updateDownloaderUsesDedicatedClientWithoutDiskCache() {
        assertEquals(null, AppUpdateInstaller.updateHttpClientForTest().cache());
    }

    public static final class ResumeActivity extends SystemInsetActivity {
        @Override protected void onCreate(Bundle state) {
            super.onCreate(state);
            setContentView(new TextView(this));
        }
    }
}
