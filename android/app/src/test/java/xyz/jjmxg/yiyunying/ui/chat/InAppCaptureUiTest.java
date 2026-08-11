package xyz.jjmxg.yiyunying.ui.chat;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertNotNull;
import static org.junit.Assert.assertTrue;

import android.app.Activity;
import android.view.View;
import android.view.ViewGroup;
import android.widget.TextView;
import android.util.Size;

import org.junit.Test;
import org.junit.runner.RunWith;
import org.robolectric.Robolectric;
import org.robolectric.RobolectricTestRunner;
import org.robolectric.android.controller.ActivityController;
import org.robolectric.annotation.Config;

import xyz.jjmxg.yiyunying.R;

@RunWith(RobolectricTestRunner.class)
@Config(sdk = 35)
public final class InAppCaptureUiTest {
    @Test public void captureSurfaceExplainsTapHoldAndSixtySecondLimit() {
        ActivityController<Activity> controller = Robolectric.buildActivity(Activity.class);
        controller.get().setTheme(R.style.Theme_Yiyunying);
        controller.setup();
        View root = controller.get().getLayoutInflater()
            .inflate(R.layout.activity_in_app_capture, null, false);

        assertTrue(((ViewGroup) root).isMotionEventSplittingEnabled());
        View preview = root.findViewById(R.id.capturePreview);
        assertNotNull(preview);
        assertTrue(preview.isClickable());
        assertTrue(preview.getContentDescription().toString().contains("双指缩放"));
        assertNotNull(root.findViewById(R.id.captureClose));
        assertNotNull(root.findViewById(R.id.captureSwitchCamera));
        assertNotNull(root.findViewById(R.id.captureFocusFrame));
        assertNotNull(root.findViewById(R.id.captureFocusStatus));
        assertEquals(View.GONE, root.findViewById(R.id.captureFocusIndicator).getVisibility());
        View zoomSeek = root.findViewById(R.id.captureZoomSeek);
        assertNotNull(zoomSeek);
        assertTrue(zoomSeek.getContentDescription().toString().contains("当前 1.0 倍"));
        assertEquals("1.0×", ((TextView) root.findViewById(R.id.captureZoomValue)).getText().toString());
        assertNotNull(root.findViewById(R.id.captureReviewImage));
        assertNotNull(root.findViewById(R.id.captureReviewVideo));
        View reviewContainer = root.findViewById(R.id.captureReviewContainer);
        assertEquals(View.GONE, reviewContainer.getVisibility());
        assertTrue(reviewContainer.isClickable());
        assertEquals(View.GONE, root.findViewById(R.id.captureReviewActions).getVisibility());
        assertEquals("取消/重拍", ((TextView) root.findViewById(R.id.captureRetake)).getText().toString());
        assertTrue(((TextView) root.findViewById(R.id.captureConfirm)).getText().toString().contains("确认"));
        View shutter = root.findViewById(R.id.captureShutter);
        assertNotNull(shutter);
        assertTrue(shutter.isClickable());
        TextView hint = root.findViewById(R.id.captureHint);
        assertTrue(hint.getText().toString().contains("轻触拍照"));
        assertTrue(hint.getText().toString().contains("长按录像"));
        assertTrue(hint.getText().toString().contains("60 秒"));
        assertTrue(hint.getText().toString().contains("点按聚焦"));
        assertTrue(hint.getText().toString().contains("拖动跟焦"));
        assertTrue(hint.getText().toString().contains("长按锁焦"));
        assertTrue(hint.getText().toString().contains("解锁"));
        assertTrue(hint.getText().toString().contains("调焦距"));
        TextView timer = root.findViewById(R.id.recordingTimer);
        assertEquals("00:00 / 01:00", timer.getText().toString());
        assertEquals(View.GONE, timer.getVisibility());

        controller.pause().stop().destroy();
    }

    @Test public void sourceSizeSelectionStaysBoundedAndUsesSmallestFallback() {
        Size bounded = InAppCaptureActivity.chooseBoundedSize(new Size[] {
            new Size(4000, 3000), new Size(1920, 1080), new Size(1280, 720), new Size(640, 480)
        }, 1280, 720, 16f / 9f);
        assertEquals(new Size(1280, 720), bounded);

        Size fallback = InAppCaptureActivity.chooseBoundedSize(new Size[] {
            new Size(7680, 4320), new Size(3840, 2160), new Size(2560, 1440)
        }, 1280, 720, 16f / 9f);
        assertEquals(new Size(2560, 1440), fallback);
    }
}
