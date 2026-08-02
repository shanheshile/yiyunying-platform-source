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

    @Test public void pinnedDividerOnlySeparatesPinnedAndRegularSections() {
        assertTrue(MomentDisplayPolicy.showsPinnedDivider(true, true, true, false));
        assertFalse(MomentDisplayPolicy.showsPinnedDivider(true, true, false, false));
        assertFalse(MomentDisplayPolicy.showsPinnedDivider(true, true, true, true));
        assertFalse(MomentDisplayPolicy.showsPinnedDivider(false, true, true, false));
    }

    @Test public void hideOnlyPermissionStillShowsMomentActions() {
        assertTrue(MomentDisplayPolicy.isManageable(false, false, false, true, false));
    }

    @Test public void visibilityOnlyPermissionStillShowsMomentActions() {
        assertTrue(MomentDisplayPolicy.isManageable(false, false, true, false, false));
    }

    @Test public void selectedAndExcludedVisibilityUseFriendSelection() {
        assertTrue(MomentDisplayPolicy.requiresFriendSelection("selected"));
        assertTrue(MomentDisplayPolicy.requiresFriendSelection("exclude"));
        assertFalse(MomentDisplayPolicy.requiresFriendSelection("friends"));
    }

    @Test public void ownProfileAlwaysUsesAuthoritativeMineQuery() {
        assertTrue(MomentDisplayPolicy.usesMineQuery(true, true, 0L, 19L));
        assertTrue(MomentDisplayPolicy.usesMineQuery(true, false, 19L, 19L));
        assertFalse(MomentDisplayPolicy.usesMineQuery(true, false, 20L, 19L));
        assertFalse(MomentDisplayPolicy.usesMineQuery(false, true, 0L, 19L));
    }
}
