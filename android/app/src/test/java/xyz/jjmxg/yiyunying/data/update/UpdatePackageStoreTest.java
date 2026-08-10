package xyz.jjmxg.yiyunying.data.update;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertNotNull;
import static org.junit.Assert.assertNull;
import static org.junit.Assert.assertTrue;
import static org.junit.Assert.fail;

import android.content.Context;

import androidx.test.core.app.ApplicationProvider;

import com.google.gson.JsonObject;

import org.junit.After;
import org.junit.Before;
import org.junit.Test;
import org.junit.runner.RunWith;
import org.robolectric.RobolectricTestRunner;
import org.robolectric.annotation.Config;

import java.io.File;
import java.io.FileOutputStream;
import java.io.IOException;

import xyz.jjmxg.yiyunying.BuildConfig;
import xyz.jjmxg.yiyunying.YiyunyingApplication;

@RunWith(RobolectricTestRunner.class)
@Config(sdk = 34, application = YiyunyingApplication.class)
public final class UpdatePackageStoreTest {
    private static final String HASH =
        "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa";

    private Context context;

    @Before public void setUp() {
        context = ApplicationProvider.getApplicationContext();
        reset();
    }

    @After public void tearDown() {
        reset();
    }

    @Test public void prepareRequiresCompleteTrustedMetadataAndUsesControlledDirectory() {
        UpdatePackageStore.Entry entry = UpdatePackageStore.prepare(
            context, update(BuildConfig.VERSION_CODE + 1L, 8L), "https://example.com/update.apk");

        assertEquals(context.getPackageName(), entry.packageName);
        assertEquals(8L, entry.expectedSize);
        assertEquals(HASH, entry.sha256);
        assertEquals("https://example.com/update.apk", entry.downloadUrl);
        assertEquals(new File(context.getFilesDir(), "update_packages").getAbsolutePath(),
            UpdatePackageStore.partFile(context, entry.id).getParentFile().getAbsolutePath());
        assertTrue(UpdatePackageStore.partFile(context, entry.id).getName().matches("[a-f0-9]{64}\\.part"));

        expectInvalid(update(0L, 8L));
        expectInvalid(update(2L, 0L));
        JsonObject badHash = update(2L, 8L);
        badHash.addProperty("sha256", "bad");
        expectInvalid(badHash);
        JsonObject wrongPackage = update(2L, 8L);
        wrongPackage.addProperty("package_name", "example.wrong.package");
        expectInvalid(wrongPackage);
    }

    @Test public void partialProgressAndPermissionStateSurviveReload() throws IOException {
        UpdatePackageStore.Entry entry = UpdatePackageStore.prepare(
            context, update(BuildConfig.VERSION_CODE + 1L, 4L), "https://example.com/update.apk");
        write(UpdatePackageStore.partFile(context, entry.id), new byte[] { 1, 2 });
        UpdatePackageStore.updateValidators(context, entry.id, "\"etag-one\"", "yesterday", 2L);
        UpdatePackageStore.markPermissionPending(context, entry.id, true);

        UpdatePackageStore.Entry restored = UpdatePackageStore.find(context, entry.id);
        assertNotNull(restored);
        assertTrue(restored.hasPart);
        assertEquals(2L, restored.bytes);
        assertEquals("\"etag-one\"", restored.etag);
        assertTrue(restored.forced);
        assertEquals(UpdatePackageStore.STATE_PERMISSION_PENDING, restored.state);
        assertEquals(entry.id, UpdatePackageStore.newestPermissionPending(context).id);
        assertEquals("https://example.com/update.apk", restored.snapshot().get("download_url").getAsString());
    }

    @Test public void successfulInstallDeletesOnlyWhenPreferenceWasSnapshotted() throws IOException {
        UpdatePackageStore.Entry retained = completed(BuildConfig.VERSION_CODE, "b");
        UpdatePackageStore.setAutoDeleteAfterInstall(context, false);
        UpdatePackageStore.markInstallRequested(context, retained.id);
        UpdatePackageStore.reconcileInstalledAfterReplacement(context);
        UpdatePackageStore.Entry retainedResult = UpdatePackageStore.find(context, retained.id);
        assertNotNull(retainedResult);
        assertTrue(retainedResult.hasApk);
        assertEquals(UpdatePackageStore.STATE_INSTALLED, retainedResult.state);

        UpdatePackageStore.Entry deleted = completed(BuildConfig.VERSION_CODE, "c");
        UpdatePackageStore.setAutoDeleteAfterInstall(context, true);
        UpdatePackageStore.Entry requested = UpdatePackageStore.markInstallRequested(context, deleted.id);
        assertTrue(requested.deleteAfterInstall);
        UpdatePackageStore.reconcileInstalledAfterReplacement(context);
        UpdatePackageStore.Entry deletedResult = UpdatePackageStore.find(context, deleted.id);
        assertNotNull(deletedResult);
        assertFalse(deletedResult.hasApk);
        assertEquals(UpdatePackageStore.STATE_INSTALLED_AUTO_DELETED, deletedResult.state);
    }

    @Test public void prepareDoesNotErasePendingInstallOrAutomaticCleanup() throws IOException {
        UpdatePackageStore.Entry entry = completed(BuildConfig.VERSION_CODE, "e");
        UpdatePackageStore.setAutoDeleteAfterInstall(context, true);
        UpdatePackageStore.markInstallRequested(context, entry.id);

        UpdatePackageStore.Entry preparedAgain = UpdatePackageStore.prepare(
            context, entry.snapshot(), entry.downloadUrl);
        assertEquals(UpdatePackageStore.STATE_INSTALL_REQUESTED, preparedAgain.state);

        UpdatePackageStore.reconcileInstalledAfterReplacement(context);
        UpdatePackageStore.Entry result = UpdatePackageStore.find(context, entry.id);
        assertNotNull(result);
        assertFalse(result.hasApk);
        assertEquals(UpdatePackageStore.STATE_INSTALLED_AUTO_DELETED, result.state);
    }

    @Test public void deleteRejectsUnknownIdsAndRemovesControlledPayloadAndHistory() throws IOException {
        UpdatePackageStore.Entry entry = completed(BuildConfig.VERSION_CODE + 1L, "d");
        assertFalse(UpdatePackageStore.delete(context, "../outside"));
        assertTrue(UpdatePackageStore.delete(context, entry.id));
        assertFalse(UpdatePackageStore.apkFile(context, entry.id).exists());
        assertNull(UpdatePackageStore.find(context, entry.id));
    }

    @Test public void regularStartupDoesNotTreatCancelledSameVersionInstallAsSuccess() throws IOException {
        UpdatePackageStore.Entry entry = completed(BuildConfig.VERSION_CODE, "e");
        UpdatePackageStore.setAutoDeleteAfterInstall(context, true);
        UpdatePackageStore.markInstallRequested(context, entry.id);

        UpdatePackageStore.reconcileInstalled(context);

        UpdatePackageStore.Entry result = UpdatePackageStore.find(context, entry.id);
        assertNotNull(result);
        assertTrue(result.hasApk);
        assertEquals(UpdatePackageStore.STATE_INSTALL_REQUESTED, result.state);
    }

    @Test public void autoDeleteIsDeviceSettingAndDefaultsOff() {
        assertFalse(UpdatePackageStore.autoDeleteAfterInstall(context));
        UpdatePackageStore.setAutoDeleteAfterInstall(context, true);
        assertTrue(UpdatePackageStore.getAutoDeleteAfterInstall(context));
    }

    private UpdatePackageStore.Entry completed(long versionCode, String hashPrefix) throws IOException {
        String hash = (hashPrefix
            + "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa").substring(0, 64);
        JsonObject update = update(versionCode, 4L);
        update.addProperty("sha256", hash);
        UpdatePackageStore.Entry entry = UpdatePackageStore.prepare(
            context, update, "https://example.com/" + hashPrefix + ".apk");
        write(UpdatePackageStore.apkFile(context, entry.id), new byte[] { 1, 2, 3, 4 });
        return UpdatePackageStore.markComplete(context, entry.id);
    }

    private JsonObject update(long versionCode, long size) {
        JsonObject update = new JsonObject();
        update.addProperty("version_name", "test");
        update.addProperty("version_code", versionCode);
        update.addProperty("size_bytes", size);
        update.addProperty("sha256", HASH);
        return update;
    }

    private void expectInvalid(JsonObject update) {
        try {
            UpdatePackageStore.prepare(context, update, "https://example.com/update.apk");
            fail("Expected invalid update metadata");
        } catch (IllegalArgumentException expected) {
            assertNotNull(expected.getMessage());
        }
    }

    private static void write(File file, byte[] data) throws IOException {
        File parent = file.getParentFile();
        if (parent != null && !parent.exists() && !parent.mkdirs()) {
            throw new IOException("Cannot create test directory");
        }
        try (FileOutputStream output = new FileOutputStream(file, false)) {
            output.write(data);
        }
    }

    private void reset() {
        UpdatePackageStore.deleteAll(context);
        context.getSharedPreferences("update_packages_v1", Context.MODE_PRIVATE).edit().clear().commit();
        File directory = new File(context.getFilesDir(), "update_packages");
        File[] children = directory.listFiles();
        if (children != null) for (File child : children) child.delete();
    }
}
