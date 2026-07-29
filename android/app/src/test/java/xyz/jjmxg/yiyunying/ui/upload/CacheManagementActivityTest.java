package xyz.jjmxg.yiyunying.ui.upload;

import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertNotNull;

import org.junit.Test;
import org.junit.runner.RunWith;
import org.robolectric.Robolectric;
import org.robolectric.RobolectricTestRunner;
import org.robolectric.android.controller.ActivityController;
import org.robolectric.annotation.Config;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.YiyunyingApplication;

@RunWith(RobolectricTestRunner.class)
@Config(sdk = 34, application = YiyunyingApplication.class)
public final class CacheManagementActivityTest {
    @Test
    public void cacheManagementLayoutAndGeneratedBindingStayInSync() {
        try (ActivityController<CacheManagementActivity> controller =
                 Robolectric.buildActivity(CacheManagementActivity.class).setup()) {
            CacheManagementActivity activity = controller.get();

            assertFalse(activity.isFinishing());
            assertFalse(activity.isDestroyed());
            assertNotNull(activity.findViewById(R.id.toolbar));
            assertNotNull(activity.findViewById(R.id.progress));
            assertNotNull(activity.findViewById(R.id.cacheSize));
            assertNotNull(activity.findViewById(R.id.clearCacheButton));
            assertNotNull(activity.findViewById(R.id.downloadsButton));
            assertNotNull(activity.findViewById(R.id.clearDataButton));
        }
    }
}
