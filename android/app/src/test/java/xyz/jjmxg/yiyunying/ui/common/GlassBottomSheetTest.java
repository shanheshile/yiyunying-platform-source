package xyz.jjmxg.yiyunying.ui.common;

import android.content.Context;
import android.graphics.Color;
import android.graphics.drawable.ColorDrawable;
import android.graphics.drawable.RippleDrawable;
import android.view.ContextThemeWrapper;
import android.view.View;

import androidx.test.core.app.ApplicationProvider;

import com.google.android.material.button.MaterialButton;

import org.junit.Test;
import org.junit.runner.RunWith;
import org.robolectric.RobolectricTestRunner;
import org.robolectric.annotation.Config;

import xyz.jjmxg.yiyunying.R;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertNull;
import static org.junit.Assert.assertTrue;

@RunWith(RobolectricTestRunner.class)
@Config(sdk = 35)
public final class GlassBottomSheetTest {
    @Test
    public void actionButtonKeepsSoftwareDrawnRoundedRippleWithoutVendorOutline() {
        Context application = ApplicationProvider.getApplicationContext();
        Context context = new ContextThemeWrapper(application, R.style.Theme_Yiyunying);
        MaterialButton button = new MaterialButton(context);
        // Reproduce the production path where another appearance pass already replaced the
        // MaterialButton-managed background before the shared bottom sheet is restyled.
        button.setBackground(new ColorDrawable(Color.RED));

        GlassBottomSheet.styleActionButton(button, context, true, 16);
        // Dialog appearance can be applied by both attach and show callbacks. Reapplying must
        // remain safe after the first call replaced MaterialButton's managed background.
        GlassBottomSheet.styleActionButton(button, context, true, 16);

        assertTrue(button.getBackground() instanceof RippleDrawable);
        assertNull(button.getOutlineProvider());
        assertFalse(button.getClipToOutline());
        assertEquals(View.LAYER_TYPE_SOFTWARE, button.getLayerType());

        assertTrue(button.getMinimumHeight() >= dp(context, 48));
        assertTrue(button.getMinimumWidth() >= dp(context, 48));
    }

    private static int dp(Context context, int value) {
        return Math.round(value * context.getResources().getDisplayMetrics().density);
    }
}
