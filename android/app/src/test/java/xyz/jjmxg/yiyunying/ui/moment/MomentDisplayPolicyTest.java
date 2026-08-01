package xyz.jjmxg.yiyunying.ui.moment;

import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;

import org.junit.Test;

public class MomentDisplayPolicyTest {
    @Test public void publicTimelineDoesNotExposePinnedSections() {
        assertFalse(MomentDisplayPolicy.showsProfileSections(0L, 0L));
    }

    @Test public void profileTimelineShowsPinnedAndRegularSections() {
        assertTrue(MomentDisplayPolicy.showsProfileSections(0L, 42L));
    }

    @Test public void immersiveDetailNeverShowsTimelineSections() {
        assertFalse(MomentDisplayPolicy.showsProfileSections(7L, 42L));
    }

    @Test public void hideOnlyPermissionStillShowsMomentActions() {
        assertTrue(MomentDisplayPolicy.isManageable(false, false, true, false));
    }
}
