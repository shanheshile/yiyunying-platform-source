package xyz.jjmxg.yiyunying.ui.chat;

import android.graphics.Rect;
import android.hardware.camera2.CaptureRequest;
import android.hardware.camera2.params.MeteringRectangle;

/** Camera2 touch-focus geometry and pointer state kept independent from the camera device. */
final class CameraFocusController {
    static final float DEFAULT_REGION_FRACTION = 0.16f;
    static final int ZOOM_PROGRESS_MAX = 1000;

    private CameraFocusController() { }

    static MeteringRectangle meteringRectangle(float previewX, float previewY,
                                                int previewWidth, int previewHeight,
                                                Rect cropRegion, int sensorOrientation,
                                                int displayRotation, boolean frontFacing) {
        Rect crop = cropRegion == null ? new Rect() : new Rect(cropRegion);
        if (crop.isEmpty()) crop.set(0, 0, 1, 1);

        float normalizedX = clamp(previewX / Math.max(1f, previewWidth), 0f, 1f);
        float normalizedY = clamp(previewY / Math.max(1f, previewHeight), 0f, 1f);

        // The user sees a mirrored front preview. Undo that mirror before undoing the sensor-to-
        // display rotation so the touched subject and the sensor metering region remain identical.
        if (frontFacing) normalizedX = 1f - normalizedX;
        int relativeRotation = normalizeDegrees(frontFacing
            ? sensorOrientation + displayRotation
            : sensorOrientation - displayRotation);

        float sensorX;
        float sensorY;
        if (relativeRotation == 90) {
            sensorX = normalizedY;
            sensorY = 1f - normalizedX;
        } else if (relativeRotation == 180) {
            sensorX = 1f - normalizedX;
            sensorY = 1f - normalizedY;
        } else if (relativeRotation == 270) {
            sensorX = 1f - normalizedY;
            sensorY = normalizedX;
        } else {
            sensorX = normalizedX;
            sensorY = normalizedY;
        }

        int cropWidth = Math.max(1, crop.width());
        int cropHeight = Math.max(1, crop.height());
        int centerX = crop.left + Math.round(sensorX * Math.max(0, cropWidth - 1));
        int centerY = crop.top + Math.round(sensorY * Math.max(0, cropHeight - 1));
        int side = Math.max(1, Math.min(Math.min(cropWidth, cropHeight),
            Math.round(Math.min(cropWidth, cropHeight) * DEFAULT_REGION_FRACTION)));
        int left = clamp(centerX - side / 2, crop.left, crop.right - side);
        int top = clamp(centerY - side / 2, crop.top, crop.bottom - side);
        return new MeteringRectangle(new Rect(left, top, left + side, top + side),
            MeteringRectangle.METERING_WEIGHT_MAX);
    }

    static int touchAfMode(int[] availableModes) {
        if (contains(availableModes, CaptureRequest.CONTROL_AF_MODE_AUTO)) {
            return CaptureRequest.CONTROL_AF_MODE_AUTO;
        }
        if (contains(availableModes, CaptureRequest.CONTROL_AF_MODE_CONTINUOUS_PICTURE)) {
            return CaptureRequest.CONTROL_AF_MODE_CONTINUOUS_PICTURE;
        }
        if (contains(availableModes, CaptureRequest.CONTROL_AF_MODE_CONTINUOUS_VIDEO)) {
            return CaptureRequest.CONTROL_AF_MODE_CONTINUOUS_VIDEO;
        }
        return CaptureRequest.CONTROL_AF_MODE_OFF;
    }

    static int continuousAfMode(int[] availableModes, boolean recording) {
        int preferred = recording
            ? CaptureRequest.CONTROL_AF_MODE_CONTINUOUS_VIDEO
            : CaptureRequest.CONTROL_AF_MODE_CONTINUOUS_PICTURE;
        if (contains(availableModes, preferred)) return preferred;
        return touchAfMode(availableModes);
    }

    static float maxZoom(Float advertisedMaximum) {
        if (advertisedMaximum == null || !Float.isFinite(advertisedMaximum)) return 1f;
        return Math.max(1f, advertisedMaximum);
    }

    static float clampZoom(float zoom, float maximumZoom) {
        float maximum = maxZoom(maximumZoom);
        if (!Float.isFinite(zoom)) return 1f;
        return clamp(zoom, 1f, maximum);
    }

    static float zoomAfterScale(float currentZoom, float scaleFactor, float maximumZoom) {
        float safeScale = Float.isFinite(scaleFactor) && scaleFactor > 0f ? scaleFactor : 1f;
        return clampZoom(currentZoom * safeScale, maximumZoom);
    }

    static float zoomFromProgress(int progress, int maximumProgress, float maximumZoom) {
        int maximum = Math.max(1, maximumProgress);
        float fraction = clamp(progress / (float) maximum, 0f, 1f);
        return 1f + (maxZoom(maximumZoom) - 1f) * fraction;
    }

    static int progressFromZoom(float zoom, int maximumProgress, float maximumZoom) {
        int maximum = Math.max(1, maximumProgress);
        float available = maxZoom(maximumZoom) - 1f;
        if (available <= 0f) return 0;
        return Math.round((clampZoom(zoom, maximumZoom) - 1f) / available * maximum);
    }

    static Rect zoomCropRegion(Rect activeArray, float zoom, float maximumZoom) {
        Rect active = activeArray == null ? new Rect() : new Rect(activeArray);
        if (active.isEmpty()) active.set(0, 0, 1, 1);
        float safeZoom = clampZoom(zoom, maximumZoom);
        int width = Math.max(1, Math.round(active.width() / safeZoom));
        int height = Math.max(1, Math.round(active.height() / safeZoom));
        int left = active.left + Math.max(0, (active.width() - width) / 2);
        int top = active.top + Math.max(0, (active.height() - height) / 2);
        return new Rect(left, top, Math.min(active.right, left + width),
            Math.min(active.bottom, top + height));
    }

    static boolean canAcceptFocus(boolean cameraReady, boolean sessionReady,
                                  boolean requestBuilderReady, boolean captureBusy,
                                  boolean recording, boolean recordingStarting,
                                  boolean reviewingCapture) {
        return cameraReady && sessionReady && requestBuilderReady && !recordingStarting
            && !reviewingCapture && (recording || !captureBusy);
    }

    private static boolean contains(int[] values, int wanted) {
        if (values == null) return false;
        for (int value : values) if (value == wanted) return true;
        return false;
    }

    private static int normalizeDegrees(int value) {
        int normalized = ((value % 360) + 360) % 360;
        return ((normalized + 45) / 90 * 90) % 360;
    }

    private static float clamp(float value, float minimum, float maximum) {
        return Math.max(minimum, Math.min(maximum, value));
    }

    private static int clamp(int value, int minimum, int maximum) {
        return Math.max(minimum, Math.min(maximum, value));
    }

    static final class GestureState {
        private int pointerId = -1;
        private float x;
        private float y;
        private boolean active;
        private boolean locked;

        void begin(int pointerId, float x, float y) {
            this.pointerId = pointerId;
            this.x = x;
            this.y = y;
            active = true;
            locked = false;
        }

        boolean move(int pointerId, float x, float y) {
            if (!matches(pointerId) || locked) return false;
            this.x = x;
            this.y = y;
            return true;
        }

        boolean lock() {
            if (!active || locked) return false;
            locked = true;
            return true;
        }

        /** Returns true when release should run the normal one-shot focus instead of retaining a lock. */
        boolean end(int pointerId, float x, float y) {
            if (!matches(pointerId)) return false;
            if (!locked) {
                this.x = x;
                this.y = y;
            }
            boolean triggerTransientFocus = !locked;
            active = false;
            this.pointerId = -1;
            return triggerTransientFocus;
        }

        void cancel() {
            active = false;
            pointerId = -1;
            locked = false;
        }

        boolean matches(int pointerId) {
            return active && this.pointerId == pointerId;
        }

        boolean isActive() { return active; }
        boolean isLocked() { return locked; }
        float x() { return x; }
        float y() { return y; }
    }
}
