package xyz.jjmxg.yiyunying.ui.common;

import android.content.Context;
import android.graphics.Bitmap;
import android.graphics.Color;
import android.graphics.drawable.ColorDrawable;
import android.view.ContextThemeWrapper;
import android.view.View;
import android.view.ViewGroup;
import android.widget.ImageView;
import android.widget.TextView;

import androidx.test.core.app.ApplicationProvider;

import org.junit.Test;
import org.junit.runner.RunWith;
import org.robolectric.RobolectricTestRunner;
import org.robolectric.annotation.Config;

import xyz.jjmxg.yiyunying.R;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertTrue;

@RunWith(RobolectricTestRunner.class)
@Config(sdk = 35)
public final class QrShareDialogTest {
    @Test
    @Config(qualifiers = "notnight")
    public void lightThemeUsesReadableDetailAndWhiteQrSurface() {
        assertThemeColors();
    }

    @Test
    @Config(qualifiers = "night")
    public void darkThemeUsesReadableDetailAndKeepsQrScannable() {
        assertThemeColors();
    }

    private void assertThemeColors() {
        Context application = ApplicationProvider.getApplicationContext();
        Context context = new ContextThemeWrapper(application, R.style.Theme_Yiyunying);
        Bitmap bitmap = Bitmap.createBitmap(2, 2, Bitmap.Config.ARGB_8888);
        View content = QrShareDialog.contentView(context, bitmap, "聊天室号：123456");
        TextView detail = content.findViewById(R.id.qrDetails);
        ImageView image = content.findViewById(R.id.qrImage);

        assertEquals(context.getColor(R.color.on_surface_variant),
            detail.getTextColors().getDefaultColor());
        assertTrue(image.getBackground() instanceof ColorDrawable);
        assertEquals(Color.WHITE, ((ColorDrawable) image.getBackground()).getColor());
        assertTrue(((ViewGroup) content).getClipChildren());
        assertTrue(((ViewGroup) content).getClipToPadding());
    }
}
