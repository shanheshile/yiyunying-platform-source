package xyz.jjmxg.yiyunying.ui.common;

import android.app.Activity;
import android.os.Looper;
import android.view.View;
import android.view.ViewGroup;
import android.widget.TextView;

import androidx.appcompat.app.AlertDialog;

import org.junit.Test;
import org.junit.runner.RunWith;
import org.robolectric.Robolectric;
import org.robolectric.RobolectricTestRunner;
import org.robolectric.Shadows;
import org.robolectric.android.controller.ActivityController;
import org.robolectric.annotation.Config;

import xyz.jjmxg.yiyunying.R;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertNotNull;
import static org.junit.Assert.assertTrue;

@RunWith(RobolectricTestRunner.class)
@Config(sdk = 35)
public final class YiyunyingDialogBuilderUiTest {
    @Test
    @Config(qualifiers = "notnight")
    public void lightDialogTitleAndMessageUseSurfaceForegrounds() {
        assertReadableDialogColors();
    }

    @Test
    @Config(qualifiers = "night")
    public void darkDialogTitleAndMessageUseSurfaceForegrounds() {
        assertReadableDialogColors();
    }

    private void assertReadableDialogColors() {
        ActivityController<Activity> controller = Robolectric.buildActivity(Activity.class);
        Activity activity = controller.get();
        activity.setTheme(R.style.Theme_Yiyunying);
        controller.setup();

        AlertDialog dialog = new YiyunyingDialogBuilder(activity)
            .setTitle("聊天室二维码")
            .setMessage("聊天室号：123456")
            .setPositiveButton("分享", null)
            .setNegativeButton("关闭", null)
            .show();
        Shadows.shadowOf(Looper.getMainLooper()).idle();

        View decor = dialog.getWindow().getDecorView();
        TextView title = findText(decor, "聊天室二维码");
        TextView message = dialog.findViewById(android.R.id.message);
        View topPanel = ModalLayerGuard.findAlertPanel(decor, "topPanel");
        View contentPanel = ModalLayerGuard.findAlertPanel(decor, "contentPanel");
        assertNotNull(title);
        assertNotNull(message);
        assertNotNull(topPanel);
        assertNotNull(contentPanel);
        assertEquals(activity.getColor(R.color.on_surface),
            title.getTextColors().getDefaultColor());
        assertEquals(activity.getColor(R.color.on_surface_variant),
            message.getTextColors().getDefaultColor());
        assertNotNull(topPanel.getBackground());
        assertTrue(topPanel.getElevation() > 0f);
        assertTrue(((ViewGroup) contentPanel).getClipChildren());
        assertTrue(((ViewGroup) contentPanel).getClipToPadding());

        dialog.dismiss();

        AlertDialog share = new YiyunyingDialogBuilder(activity)
            .setTitle("分享")
            .setItems(new String[]{"发送给好友", "系统分享"}, null)
            .setNegativeButton("取消", null)
            .show();
        Shadows.shadowOf(Looper.getMainLooper()).idle();
        View shareDecor = share.getWindow().getDecorView();
        layout(shareDecor);
        shareDecor.getViewTreeObserver().dispatchOnPreDraw();
        TextView shareRow = findText(shareDecor, "系统分享");
        assertNotNull(shareRow);
        assertEquals(activity.getColor(R.color.on_surface),
            shareRow.getTextColors().getDefaultColor());
        share.dismiss();
        controller.pause().stop().destroy();
    }

    private static void layout(View view) {
        int width = View.MeasureSpec.makeMeasureSpec(1080, View.MeasureSpec.EXACTLY);
        int height = View.MeasureSpec.makeMeasureSpec(1920, View.MeasureSpec.AT_MOST);
        view.measure(width, height);
        view.layout(0, 0, view.getMeasuredWidth(), view.getMeasuredHeight());
    }

    private static TextView findText(View view, String expected) {
        if (view instanceof TextView && expected.contentEquals(((TextView) view).getText())) {
            return (TextView) view;
        }
        if (!(view instanceof ViewGroup)) return null;
        ViewGroup group = (ViewGroup) view;
        for (int index = 0; index < group.getChildCount(); index++) {
            TextView found = findText(group.getChildAt(index), expected);
            if (found != null) return found;
        }
        return null;
    }

}
