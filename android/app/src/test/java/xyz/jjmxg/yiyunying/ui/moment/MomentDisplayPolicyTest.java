package xyz.jjmxg.yiyunying.ui.moment;

import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;

import org.junit.Test;

public class MomentDisplayPolicyTest {
    @Test public void publicTimelineDoesNotExposePinnedSections() {
        assertFalse(MomentDisplayPolicy.showsProfileSections(0L, false));
    }

    @Test public void profileTimelineMayShowPinnedSection() {
        assertTrue(MomentDisplayPolicy.showsProfileSections(0L, true));
    }

    @Test public void immersiveDetailNeverShowsTimelineSections() {
        assertFalse(MomentDisplayPolicy.showsProfileSections(7L, true));
    }

    @Test public void hideOnlyPermissionStillShowsMomentActions() {
        assertTrue(MomentDisplayPolicy.isManageable(false, false, false, true, false));
    }

    @Test public void visibilityOnlyPermissionStillShowsMomentActions() {
        assertTrue(MomentDisplayPolicy.isManageable(false, false, true, false, false));
    }
}
