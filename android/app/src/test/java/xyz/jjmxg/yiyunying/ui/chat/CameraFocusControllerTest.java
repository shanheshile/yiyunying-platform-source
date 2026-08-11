package xyz.jjmxg.yiyunying.ui.chat;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;

import android.graphics.Rect;
import android.hardware.camera2.CaptureRequest;
import android.hardware.camera2.params.MeteringRectangle;

import org.junit.Test;
import org.junit.runner.RunWith;
import org.robolectric.RobolectricTestRunner;
import org.robolectric.annotation.Config;

@RunWith(RobolectricTestRunner.class)
@Config(sdk = 35)
public final class CameraFocusControllerTest {
    @Test public void backCameraTouchMapsThroughSensorRotationAndCropBounds() {
        Rect crop = new Rect(100, 200, 4100, 3200);
        MeteringRectangle topLeft = CameraFocusController.meteringRectangle(
            0f, 0f, 1000, 500, crop, 90, 0, false);

        assertEquals(new Rect(100, 2720, 580, 3200), topLeft.getRect());

        MeteringRectangle center = CameraFocusController.meteringRectangle(
            500f, 250f, 1000, 500, crop, 90, 0, false);
        assertEquals(new Rect(1860, 1460, 2340, 1940), center.getRect());
    }

    @Test public void frontCameraUndoesMirroredPreviewBeforeSensorMapping() {
        Rect crop = new Rect(0, 0, 4000, 3000);
        MeteringRectangle backLeft = CameraFocusController.meteringRectangle(
            0f, 250f, 1000, 500, crop, 0, 0, false);
        MeteringRectangle frontLeft = CameraFocusController.meteringRectangle(
            0f, 250f, 1000, 500, crop, 0, 0, true);

        assertEquals(0, backLeft.getRect().left);
        assertEquals(3520, frontLeft.getRect().left);
        assertEquals(backLeft.getRect().top, frontLeft.getRect().top);
    }

    @Test public void outOfBoundsTouchIsClampedInsideCurrentCropRegion() {
        Rect crop = new Rect(200, 400, 1200, 1400);
        Rect metering = CameraFocusController.meteringRectangle(
            -900f, 900f, 400, 800, crop, 0, 0, false).getRect();

        assertTrue(crop.contains(metering.left, metering.top));
        assertTrue(metering.right <= crop.right);
        assertTrue(metering.bottom <= crop.bottom);
        assertEquals(160, metering.width());
        assertEquals(160, metering.height());
    }

    @Test public void dragFollowsPointerAndLongPressRetainsLockedPosition() {
        CameraFocusController.GestureState gesture = new CameraFocusController.GestureState();
        gesture.begin(7, 10f, 20f);
        assertTrue(gesture.move(7, 80f, 90f));
        assertEquals(80f, gesture.x(), 0f);
        assertEquals(90f, gesture.y(), 0f);

        assertTrue(gesture.lock());
        assertFalse(gesture.move(7, 150f, 160f));
        assertFalse(gesture.end(7, 150f, 160f));
        assertEquals(80f, gesture.x(), 0f);
        assertEquals(90f, gesture.y(), 0f);

        gesture.begin(9, 30f, 40f);
        assertTrue(gesture.end(9, 35f, 45f));
        assertEquals(35f, gesture.x(), 0f);
        assertEquals(45f, gesture.y(), 0f);
    }

    @Test public void autofocusModesUseRealDeviceCapabilitiesWithSafeFallbacks() {
        assertEquals(CaptureRequest.CONTROL_AF_MODE_AUTO,
            CameraFocusController.touchAfMode(new int[] {
                CaptureRequest.CONTROL_AF_MODE_OFF,
                CaptureRequest.CONTROL_AF_MODE_CONTINUOUS_PICTURE,
                CaptureRequest.CONTROL_AF_MODE_AUTO
            }));
        assertEquals(CaptureRequest.CONTROL_AF_MODE_CONTINUOUS_VIDEO,
            CameraFocusController.continuousAfMode(new int[] {
                CaptureRequest.CONTROL_AF_MODE_OFF,
                CaptureRequest.CONTROL_AF_MODE_CONTINUOUS_VIDEO
            }, true));
        assertEquals(CaptureRequest.CONTROL_AF_MODE_OFF,
            CameraFocusController.touchAfMode(new int[] {
                CaptureRequest.CONTROL_AF_MODE_OFF
            }));
    }

    @Test public void digitalZoomIsCapabilityBoundedAndProducesCenteredCrop() {
        Rect active = new Rect(100, 200, 4100, 3200);
        assertEquals(1f, CameraFocusController.maxZoom(null), 0f);
        assertEquals(1f, CameraFocusController.maxZoom(Float.NaN), 0f);
        assertEquals(4f, CameraFocusController.clampZoom(9f, 4f), 0f);
        assertEquals(1f, CameraFocusController.clampZoom(0.2f, 4f), 0f);
        assertEquals(2.5f, CameraFocusController.zoomFromProgress(500, 1000, 4f), 0.001f);
        assertEquals(500, CameraFocusController.progressFromZoom(2.5f, 1000, 4f));
        assertEquals(new Rect(1100, 950, 3100, 2450),
            CameraFocusController.zoomCropRegion(active, 2f, 4f));
        assertEquals(active, CameraFocusController.zoomCropRegion(active, 1f, 4f));
    }

    @Test public void pinchZoomCannotEscapeDeviceRange() {
        assertEquals(4f, CameraFocusController.zoomAfterScale(3f, 2f, 4f), 0f);
        assertEquals(1f, CameraFocusController.zoomAfterScale(1.2f, 0.1f, 4f), 0f);
        assertEquals(2f, CameraFocusController.zoomAfterScale(2f, Float.NaN, 4f), 0f);
    }

    @Test public void recordingStillAcceptsFocusWhileBusyButTransitionAndReviewDoNot() {
        assertTrue(CameraFocusController.canAcceptFocus(true, true, true,
            true, true, false, false));
        CameraFocusController.GestureState recordingGesture =
            new CameraFocusController.GestureState();
        recordingGesture.begin(3, 120f, 240f);
        assertTrue(recordingGesture.move(3, 180f, 280f));
        assertTrue(recordingGesture.lock());
        assertTrue(recordingGesture.isLocked());
        assertFalse(CameraFocusController.canAcceptFocus(true, true, true,
            true, false, false, false));
        assertFalse(CameraFocusController.canAcceptFocus(true, true, true,
            true, true, true, false));
        assertFalse(CameraFocusController.canAcceptFocus(true, true, true,
            false, true, false, true));
    }
}
