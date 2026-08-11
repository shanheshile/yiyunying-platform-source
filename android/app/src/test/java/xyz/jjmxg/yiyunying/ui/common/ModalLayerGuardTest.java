package xyz.jjmxg.yiyunying.ui.common;

import android.content.Context;
import android.view.ContextThemeWrapper;
import android.widget.FrameLayout;
import android.widget.LinearLayout;
import android.widget.ScrollView;

import androidx.test.core.app.ApplicationProvider;

import org.junit.Test;
import org.junit.runner.RunWith;
import org.robolectric.RobolectricTestRunner;
import org.robolectric.annotation.Config;

import xyz.jjmxg.yiyunying.R;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;

@RunWith(RobolectricTestRunner.class)
@Config(sdk = 35)
public final class ModalLayerGuardTest {
    @Test
    public void bottomSheetRestoresClippingAtRootAndEveryScrollingViewport() {
        Context application = ApplicationProvider.getApplicationContext();
        Context context = new ContextThemeWrapper(application, R.style.Theme_Yiyunying);
        LinearLayout root = new LinearLayout(context);
        root.setClipChildren(false);
        root.setClipToPadding(false);

        FrameLayout viewportHost = new FrameLayout(context);
        viewportHost.setClipChildren(false);
        viewportHost.setClipToPadding(false);
        ScrollView scroll = new ScrollView(context);
        scroll.setClipChildren(false);
        scroll.setClipToPadding(false);
        scroll.setOverScrollMode(android.view.View.OVER_SCROLL_ALWAYS);
        scroll.addView(new LinearLayout(context));
        viewportHost.addView(scroll);
        root.addView(viewportHost);

        ModalLayerGuard.protectBottomSheet(root, context);

        assertTrue(root.getClipChildren());
        assertTrue(root.getClipToPadding());
        assertTrue(scroll.getClipChildren());
        assertTrue(scroll.getClipToPadding());
        assertFalse(scroll.getOverScrollMode() == android.view.View.OVER_SCROLL_ALWAYS);
        assertEquals(Boolean.TRUE, scroll.getTag(R.id.tag_bottom_sheet_drag_handoff));
    }

    @Test public void topEdgeDownwardGestureReturnsOwnershipToBottomSheet() {
        assertTrue(BottomSheetDragHandoffPolicy.shouldReleaseParent(
            true, 0f, 0f, false));
        assertTrue(BottomSheetDragHandoffPolicy.shouldReleaseParent(
            false, 100f, 112f, false));
        assertFalse(BottomSheetDragHandoffPolicy.shouldReleaseParent(
            false, 112f, 100f, false));
        assertFalse(BottomSheetDragHandoffPolicy.shouldReleaseParent(
            false, 100f, 112f, true));
    }
}
