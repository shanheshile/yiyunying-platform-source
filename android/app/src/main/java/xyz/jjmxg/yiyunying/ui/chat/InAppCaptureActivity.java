package xyz.jjmxg.yiyunying.ui.chat;

import android.Manifest;
import android.content.Context;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.graphics.ImageFormat;
import android.graphics.Rect;
import android.hardware.camera2.CameraAccessException;
import android.hardware.camera2.CameraCaptureSession;
import android.hardware.camera2.CameraCharacteristics;
import android.hardware.camera2.CameraDevice;
import android.hardware.camera2.CameraManager;
import android.hardware.camera2.CaptureRequest;
import android.hardware.camera2.CaptureResult;
import android.hardware.camera2.TotalCaptureResult;
import android.hardware.camera2.params.MeteringRectangle;
import android.hardware.camera2.params.StreamConfigurationMap;
import android.media.Image;
import android.media.ImageReader;
import android.media.MediaRecorder;
import android.net.Uri;
import android.os.Bundle;
import android.os.Handler;
import android.os.HandlerThread;
import android.os.SystemClock;
import android.view.HapticFeedbackConstants;
import android.view.MotionEvent;
import android.view.ScaleGestureDetector;
import android.view.Surface;
import android.view.SurfaceHolder;
import android.view.View;
import android.view.ViewConfiguration;
import android.view.WindowManager;
import android.widget.SeekBar;
import android.widget.Toast;

import androidx.annotation.NonNull;
import androidx.activity.OnBackPressedCallback;
import androidx.core.content.ContextCompat;
import androidx.core.content.FileProvider;
import androidx.core.graphics.Insets;
import androidx.core.view.ViewCompat;
import androidx.core.view.WindowCompat;
import androidx.core.view.WindowInsetsCompat;

import java.io.File;
import java.io.FileOutputStream;
import java.io.IOException;
import java.nio.ByteBuffer;
import java.util.ArrayList;
import java.util.Arrays;
import java.util.Collections;
import java.util.Comparator;
import java.util.List;
import java.util.Locale;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.databinding.ActivityInAppCaptureBinding;

/**
 * App-owned camera with deliberately bounded source pixels. A tap takes a photo; holding the
 * shutter records until release, with an enforced 60 second ceiling. The caller still runs the
 * shared local optimizer before upload so captured media follows the same compression path as
 * gallery media.
 */
public final class InAppCaptureActivity extends xyz.jjmxg.yiyunying.ui.common.SystemInsetActivity {
    public static final String EXTRA_CAPTURE_URI = "capture_uri";
    public static final String EXTRA_CAPTURE_VIDEO = "capture_video";

    private static final int REQUEST_PERMISSIONS = 4301;
    private static final long LONG_PRESS_MS = 360L;
    private static final long MAX_VIDEO_MS = 60_000L;
    private static final long MIN_VIDEO_MS = 450L;
    private static final long FOCUS_LOCK_PRESS_MS = 520L;
    private static final long FOCUS_FOLLOW_INTERVAL_MS = 52L;
    private static final long FOCUS_RELEASE_MS = 1_800L;
    private static final int MAX_PHOTO_WIDTH = 1920;
    private static final int MAX_PHOTO_HEIGHT = 1440;
    private static final int MAX_VIDEO_WIDTH = 1280;
    private static final int MAX_VIDEO_HEIGHT = 720;
    private static final int FOCUS_STATE_FOLLOWING = 1;
    private static final int FOCUS_STATE_FOCUSING = 2;
    private static final int FOCUS_STATE_SUCCESS = 3;
    private static final int FOCUS_STATE_LOCKING = 4;
    private static final int FOCUS_STATE_LOCKED = 5;
    private static final int FOCUS_STATE_FAILED = 6;
    private static final String STATE_CAPTURE_PATH = "capture_review_path";
    private static final String STATE_CAPTURE_VIDEO = "capture_review_video";
    private static final String STATE_REVIEWING_CAPTURE = "reviewing_capture";
    private static final String STATE_ZOOM_RATIO = "capture_zoom_ratio";
    private static final long STALE_CAPTURE_AGE_MS = 24L * 60L * 60L * 1000L;

    private ActivityInAppCaptureBinding binding;
    private final Handler mainHandler = new Handler(android.os.Looper.getMainLooper());
    private HandlerThread cameraThread;
    private Handler cameraHandler;
    private CameraDevice cameraDevice;
    private CameraCaptureSession captureSession;
    private CaptureRequest.Builder previewRequestBuilder;
    private ImageReader imageReader;
    private MediaRecorder mediaRecorder;
    private File captureFile;
    private volatile File pendingCaptureFile;
    private volatile boolean pendingCaptureVideo;
    private volatile boolean reviewingCapture;
    private String cameraId = "";
    private android.util.Size previewSize;
    private android.util.Size videoSize;
    private int lensFacing = CameraCharacteristics.LENS_FACING_BACK;
    private int sensorOrientation = 90;
    private int maxAfRegions;
    private int maxAeRegions;
    private int touchAfMode = CaptureRequest.CONTROL_AF_MODE_AUTO;
    private int pictureAfMode = CaptureRequest.CONTROL_AF_MODE_CONTINUOUS_PICTURE;
    private int videoAfMode = CaptureRequest.CONTROL_AF_MODE_CONTINUOUS_VIDEO;
    private boolean aeLockAvailable;
    private Rect activeArrayRegion = new Rect(0, 0, 1, 1);
    private volatile Rect currentCropRegion = new Rect(0, 0, 1, 1);
    private float maxDigitalZoom = 1f;
    private float currentZoomRatio = 1f;
    private boolean suppressZoomSeekCallback;
    private ScaleGestureDetector scaleGestureDetector;
    private boolean zoomGestureInSequence;
    private volatile MeteringRectangle currentMeteringRegion;
    private volatile boolean focusLocked;
    private volatile int focusRequestGeneration;
    private int awaitingFocusGeneration = -1;
    private boolean awaitingFocusLocked;
    private final CameraFocusController.GestureState focusGesture =
        new CameraFocusController.GestureState();
    private boolean followFocusPosted;
    private int focusTouchSlop;
    private float focusLockAnchorX;
    private float focusLockAnchorY;
    private boolean focusWasLockedAtGestureStart;
    private boolean surfaceReady;
    private boolean openingCamera;
    private boolean recordingRequested;
    private boolean recordingStarting;
    private boolean recording;
    private boolean longPressTriggered;
    private boolean deliveringResult;
    private boolean captureBusy;
    private long recordingStartedAt;

    private static final class FocusRequestTag {
        final int generation;

        FocusRequestTag(int generation) {
            this.generation = generation;
        }
    }

    private final Runnable lockFocusAfterHold = () -> {
        if (binding == null || !canTouchFocus()) return;
        if (focusWasLockedAtGestureStart) {
            if (!focusGesture.lock()) return;
            focusWasLockedAtGestureStart = false;
            focusLocked = false;
            binding.capturePreview.performHapticFeedback(HapticFeedbackConstants.LONG_PRESS);
            releaseFocusLockFromGesture();
            return;
        }
        if (!focusGesture.lock()) return;
        focusLocked = true;
        binding.capturePreview.performHapticFeedback(HapticFeedbackConstants.LONG_PRESS);
        updateFocusIndicator(FOCUS_STATE_LOCKING, true);
        submitTouchFocus(focusGesture.x(), focusGesture.y(), true, true);
    };

    private final Runnable submitFollowFocus = () -> {
        followFocusPosted = false;
        if (binding == null || !focusGesture.isActive() || focusGesture.isLocked()
            || !canTouchFocus()) return;
        submitTouchFocus(focusGesture.x(), focusGesture.y(), false, false);
    };

    private final Runnable beginVideoAfterHold = () -> {
        if (binding == null || captureBusy || cameraDevice == null) return;
        longPressTriggered = true;
        recordingRequested = true;
        beginVideoRecording();
    };

    private final Runnable recordingTicker = new Runnable() {
        @Override public void run() {
            if (binding == null || !recording) return;
            long elapsed = Math.min(MAX_VIDEO_MS, Math.max(0L,
                SystemClock.elapsedRealtime() - recordingStartedAt));
            binding.recordingTimer.setText(String.format(Locale.CHINA,
                "%02d:%02d / 01:00", elapsed / 60_000L, (elapsed / 1000L) % 60L));
            if (elapsed >= MAX_VIDEO_MS) {
                recordingRequested = false;
                stopVideoRecording(true);
                return;
            }
            mainHandler.postDelayed(this, 100L);
        }
    };

    private final CameraCaptureSession.CaptureCallback cameraStateCallback =
        new CameraCaptureSession.CaptureCallback() {
            @Override public void onCaptureCompleted(@NonNull CameraCaptureSession session,
                                                     @NonNull CaptureRequest request,
                                                     @NonNull TotalCaptureResult result) {
                Rect crop = result.get(CaptureResult.SCALER_CROP_REGION);
                if (crop != null && !crop.isEmpty()) currentCropRegion = new Rect(crop);
                Object tag = request.getTag();
                if (!(tag instanceof FocusRequestTag)
                    || ((FocusRequestTag) tag).generation != awaitingFocusGeneration) return;
                if (awaitingFocusGeneration < 0) return;
                Integer afState = result.get(CaptureResult.CONTROL_AF_STATE);
                if (afState == null) return;
                boolean focused = afState == CaptureResult.CONTROL_AF_STATE_FOCUSED_LOCKED
                    || afState == CaptureResult.CONTROL_AF_STATE_PASSIVE_FOCUSED;
                boolean terminal = focused
                    || afState == CaptureResult.CONTROL_AF_STATE_NOT_FOCUSED_LOCKED;
                if (!terminal) return;
                int generation = awaitingFocusGeneration;
                boolean locked = awaitingFocusLocked;
                awaitingFocusGeneration = -1;
                runOnUiThread(() -> {
                    if (binding == null || generation != focusRequestGeneration) return;
                    updateFocusIndicator(locked ? FOCUS_STATE_LOCKED
                        : (focused ? FOCUS_STATE_SUCCESS : FOCUS_STATE_FAILED), locked);
                });
            }
        };

    public static Intent intent(Context context) {
        return new Intent(context, InAppCaptureActivity.class);
    }

    @Override protected void onCreate(Bundle state) {
        super.onCreate(state);
        WindowCompat.setDecorFitsSystemWindows(getWindow(), false);
        getWindow().setStatusBarColor(android.graphics.Color.BLACK);
        getWindow().setNavigationBarColor(android.graphics.Color.BLACK);
        getWindow().addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON);
        binding = ActivityInAppCaptureBinding.inflate(getLayoutInflater());
        currentZoomRatio = state == null ? 1f : state.getFloat(STATE_ZOOM_RATIO, 1f);
        scaleGestureDetector = createScaleGestureDetector();
        focusTouchSlop = ViewConfiguration.get(this).getScaledTouchSlop();
        setContentView(binding.getRoot());
        pruneStaleCaptureFiles();
        ViewCompat.setOnApplyWindowInsetsListener(binding.getRoot(), (view, insets) -> {
            Insets bars = insets.getInsets(WindowInsetsCompat.Type.systemBars());
            binding.captureTopBar.setPadding(
                binding.captureTopBar.getPaddingLeft(), bars.top + dp(14),
                binding.captureTopBar.getPaddingRight(), binding.captureTopBar.getPaddingBottom());
            binding.captureBottomBar.setPadding(
                binding.captureBottomBar.getPaddingLeft(), binding.captureBottomBar.getPaddingTop(),
                binding.captureBottomBar.getPaddingRight(), bars.bottom + dp(28));
            binding.captureReviewActions.setPadding(
                binding.captureReviewActions.getPaddingLeft(), binding.captureReviewActions.getPaddingTop(),
                binding.captureReviewActions.getPaddingRight(), bars.bottom + dp(28));
            return insets;
        });
        binding.captureClose.setOnClickListener(view -> cancelCapture());
        binding.captureSwitchCamera.setOnClickListener(view -> switchCamera());
        binding.captureShutter.setOnClickListener(view -> takePhoto());
        binding.captureShutter.setOnTouchListener(this::handleShutterTouch);
        binding.capturePreview.setOnTouchListener(this::handlePreviewFocusTouch);
        binding.captureRetake.setOnClickListener(view -> discardCapturedPreviewAndResume());
        binding.captureConfirm.setOnClickListener(view -> confirmCapturedPreview());
        binding.captureReviewVideo.setOnClickListener(view -> {
            if (!reviewingCapture) return;
            if (binding.captureReviewVideo.isPlaying()) binding.captureReviewVideo.pause();
            else binding.captureReviewVideo.start();
        });
        binding.captureZoomSeek.setMax(CameraFocusController.ZOOM_PROGRESS_MAX);
        binding.captureZoomSeek.setOnSeekBarChangeListener(new SeekBar.OnSeekBarChangeListener() {
            @Override public void onProgressChanged(SeekBar seekBar, int progress, boolean fromUser) {
                if (!fromUser || suppressZoomSeekCallback) return;
                if (!canAdjustZoom()) {
                    updateZoomControls();
                    return;
                }
                setZoomRatio(CameraFocusController.zoomFromProgress(progress,
                    CameraFocusController.ZOOM_PROGRESS_MAX, maxDigitalZoom), true);
            }

            @Override public void onStartTrackingTouch(SeekBar seekBar) {
                beginZoomInteraction();
            }

            @Override public void onStopTrackingTouch(SeekBar seekBar) {
                if (binding != null) binding.captureZoomSeek.announceForAccessibility(
                    zoomAccessibilityDescription());
            }
        });
        getOnBackPressedDispatcher().addCallback(this, new OnBackPressedCallback(true) {
            @Override public void handleOnBackPressed() {
                if (reviewingCapture) discardCapturedPreviewAndResume();
                else cancelCapture();
            }
        });
        binding.capturePreview.getHolder().addCallback(new SurfaceHolder.Callback() {
            @Override public void surfaceCreated(@NonNull SurfaceHolder holder) {
                surfaceReady = true;
                openCameraWhenReady();
            }

            @Override public void surfaceChanged(@NonNull SurfaceHolder holder, int format, int width, int height) { }

            @Override public void surfaceDestroyed(@NonNull SurfaceHolder holder) {
                surfaceReady = false;
            }
        });
        boolean restoredReview = restoreCapturedPreview(state);
        if (!restoredReview) requestCapturePermissionsIfNeeded();
    }

    @Override protected void onResume() {
        super.onResume();
        startCameraThread();
        if (reviewingCapture) resumeReviewPlayback();
        else {
            resetRecordingUi();
            openCameraWhenReady();
        }
    }

    @Override protected void onPause() {
        mainHandler.removeCallbacks(beginVideoAfterHold);
        mainHandler.removeCallbacks(recordingTicker);
        mainHandler.removeCallbacks(lockFocusAfterHold);
        mainHandler.removeCallbacks(submitFollowFocus);
        followFocusPosted = false;
        focusGesture.cancel();
        if (!deliveringResult) recordingRequested = false;
        if (binding != null && reviewingCapture) binding.captureReviewVideo.pause();
        closeCamera();
        stopCameraThread();
        super.onPause();
    }

    @Override protected void onDestroy() {
        if (isFinishing() && !isChangingConfigurations() && !deliveringResult) {
            deletePendingCapture();
        }
        binding = null;
        super.onDestroy();
    }

    @Override protected void onSaveInstanceState(@NonNull Bundle state) {
        super.onSaveInstanceState(state);
        state.putFloat(STATE_ZOOM_RATIO, currentZoomRatio);
        File pending = pendingCaptureFile;
        if (reviewingCapture && pending != null && isManagedCaptureFile(pending) && pending.exists()) {
            state.putBoolean(STATE_REVIEWING_CAPTURE, true);
            state.putString(STATE_CAPTURE_PATH, pending.getAbsolutePath());
            state.putBoolean(STATE_CAPTURE_VIDEO, pendingCaptureVideo);
        }
    }

    private void requestCapturePermissionsIfNeeded() {
        List<String> missing = new ArrayList<>();
        if (!hasPermission(Manifest.permission.CAMERA)) missing.add(Manifest.permission.CAMERA);
        if (!hasPermission(Manifest.permission.RECORD_AUDIO)) missing.add(Manifest.permission.RECORD_AUDIO);
        if (missing.isEmpty()) {
            openCameraWhenReady();
        } else {
            requestPermissions(missing.toArray(new String[0]), REQUEST_PERMISSIONS);
        }
    }

    @Override public void onRequestPermissionsResult(int requestCode, @NonNull String[] permissions,
                                                     @NonNull int[] grantResults) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults);
        if (requestCode != REQUEST_PERMISSIONS) return;
        if (!hasPermission(Manifest.permission.CAMERA)) {
            Toast.makeText(this, "未获得相机权限，无法拍摄", Toast.LENGTH_LONG).show();
            finish();
            return;
        }
        if (!hasPermission(Manifest.permission.RECORD_AUDIO) && binding != null) {
            binding.captureHint.setText(defaultCaptureHint());
        }
        openCameraWhenReady();
    }

    private boolean hasPermission(String permission) {
        return ContextCompat.checkSelfPermission(this, permission) == PackageManager.PERMISSION_GRANTED;
    }

    private boolean handleShutterTouch(View view, MotionEvent event) {
        if (event.getActionMasked() == MotionEvent.ACTION_DOWN) {
            if (captureBusy || recordingStarting || cameraDevice == null) return true;
            longPressTriggered = false;
            view.animate().scaleX(0.92f).scaleY(0.92f).setDuration(90L).start();
            mainHandler.postDelayed(beginVideoAfterHold, LONG_PRESS_MS);
            return true;
        }
        if (event.getActionMasked() == MotionEvent.ACTION_UP
            || event.getActionMasked() == MotionEvent.ACTION_CANCEL) {
            mainHandler.removeCallbacks(beginVideoAfterHold);
            view.animate().scaleX(1f).scaleY(1f).setDuration(90L).start();
            if (longPressTriggered) {
                recordingRequested = false;
                if (recording) stopVideoRecording(false);
            } else if (event.getActionMasked() == MotionEvent.ACTION_UP) {
                view.performClick();
            }
            return true;
        }
        return true;
    }

    private boolean handlePreviewFocusTouch(View view, MotionEvent event) {
        int action = event.getActionMasked();
        if (reviewingCapture) return false;
        if (scaleGestureDetector != null) scaleGestureDetector.onTouchEvent(event);
        if (action == MotionEvent.ACTION_POINTER_DOWN) {
            zoomGestureInSequence = true;
            beginZoomInteraction();
            return true;
        }
        if (zoomGestureInSequence || event.getPointerCount() > 1
            || (scaleGestureDetector != null && scaleGestureDetector.isInProgress())) {
            if (action == MotionEvent.ACTION_UP || action == MotionEvent.ACTION_CANCEL) {
                zoomGestureInSequence = false;
            }
            return true;
        }
        if (action == MotionEvent.ACTION_DOWN) {
            if (!canTouchFocus()) return false;
            int pointerId = event.getPointerId(0);
            focusWasLockedAtGestureStart = focusLocked;
            focusGesture.begin(pointerId, clamp(event.getX(), 0f, view.getWidth()),
                clamp(event.getY(), 0f, view.getHeight()));
            focusLockAnchorX = focusGesture.x();
            focusLockAnchorY = focusGesture.y();
            mainHandler.removeCallbacks(lockFocusAfterHold);
            mainHandler.removeCallbacks(submitFollowFocus);
            followFocusPosted = false;
            if (!focusWasLockedAtGestureStart) {
                positionFocusIndicator(focusGesture.x(), focusGesture.y(), true);
                updateFocusIndicator(FOCUS_STATE_FOLLOWING, false);
                submitTouchFocus(focusGesture.x(), focusGesture.y(), false, false);
            }
            mainHandler.postDelayed(lockFocusAfterHold, FOCUS_LOCK_PRESS_MS);
            return true;
        }

        int actionIndex = event.getActionIndex();
        int pointerId = event.getPointerId(actionIndex);
        if (action == MotionEvent.ACTION_MOVE) {
            if (!focusGesture.isActive()) return false;
            int trackedIndex = event.findPointerIndex(event.getPointerId(0));
            // The tracked pointer is normally index 0. If another finger is added, find the one
            // already owned by GestureState rather than transferring focus to the new pointer.
            for (int index = 0; index < event.getPointerCount(); index++) {
                if (focusGesture.matches(event.getPointerId(index))) {
                    trackedIndex = index;
                    break;
                }
            }
            if (trackedIndex < 0 || !focusGesture.move(event.getPointerId(trackedIndex),
                clamp(event.getX(trackedIndex), 0f, view.getWidth()),
                clamp(event.getY(trackedIndex), 0f, view.getHeight()))) return true;
            boolean movedBeyondSlop = distance(focusLockAnchorX, focusLockAnchorY,
                focusGesture.x(), focusGesture.y()) > focusTouchSlop;
            if (focusWasLockedAtGestureStart && !movedBeyondSlop) return true;
            if (focusWasLockedAtGestureStart) {
                focusWasLockedAtGestureStart = false;
                focusLocked = false;
            }
            positionFocusIndicator(focusGesture.x(), focusGesture.y(), false);
            updateFocusIndicator(FOCUS_STATE_FOLLOWING, false);
            if (movedBeyondSlop) {
                focusLockAnchorX = focusGesture.x();
                focusLockAnchorY = focusGesture.y();
                mainHandler.removeCallbacks(lockFocusAfterHold);
                mainHandler.postDelayed(lockFocusAfterHold, FOCUS_LOCK_PRESS_MS);
            }
            if (!followFocusPosted) {
                followFocusPosted = true;
                mainHandler.postDelayed(submitFollowFocus, FOCUS_FOLLOW_INTERVAL_MS);
            }
            return true;
        }

        if (action == MotionEvent.ACTION_UP) {
            mainHandler.removeCallbacks(lockFocusAfterHold);
            mainHandler.removeCallbacks(submitFollowFocus);
            followFocusPosted = false;
            if (!focusGesture.matches(pointerId)) return true;
            float x = clamp(event.getX(actionIndex), 0f, view.getWidth());
            float y = clamp(event.getY(actionIndex), 0f, view.getHeight());
            boolean runTransientFocus = focusGesture.end(pointerId, x, y);
            if (runTransientFocus) {
                focusWasLockedAtGestureStart = false;
                focusLocked = false;
                positionFocusIndicator(focusGesture.x(), focusGesture.y(), false);
                updateFocusIndicator(FOCUS_STATE_FOCUSING, false);
                submitTouchFocus(focusGesture.x(), focusGesture.y(), true, false);
            }
            view.performClick();
            return true;
        }

        if (action == MotionEvent.ACTION_CANCEL) {
            mainHandler.removeCallbacks(lockFocusAfterHold);
            mainHandler.removeCallbacks(submitFollowFocus);
            followFocusPosted = false;
            boolean locked = focusGesture.isLocked();
            focusGesture.cancel();
            focusWasLockedAtGestureStart = false;
            if (!locked) hideFocusIndicator();
            return true;
        }
        return focusGesture.isActive();
    }

    private boolean canTouchFocus() {
        return binding != null && CameraFocusController.canAcceptFocus(
            cameraDevice != null, captureSession != null, previewRequestBuilder != null,
            captureBusy, recording, recordingStarting, reviewingCapture);
    }

    private ScaleGestureDetector createScaleGestureDetector() {
        return new ScaleGestureDetector(this, new ScaleGestureDetector.SimpleOnScaleGestureListener() {
            @Override public boolean onScaleBegin(ScaleGestureDetector detector) {
                if (!canAdjustZoom()) return false;
                beginZoomInteraction();
                return true;
            }

            @Override public boolean onScale(ScaleGestureDetector detector) {
                if (!canAdjustZoom()) return false;
                setZoomRatio(CameraFocusController.zoomAfterScale(currentZoomRatio,
                    detector.getScaleFactor(), maxDigitalZoom), true);
                return true;
            }

            @Override public void onScaleEnd(ScaleGestureDetector detector) {
                if (binding != null) binding.capturePreview.announceForAccessibility(
                    zoomAccessibilityDescription());
            }
        });
    }

    private boolean canAdjustZoom() {
        return binding != null && CameraFocusController.canAcceptFocus(
            cameraDevice != null, captureSession != null, previewRequestBuilder != null,
            captureBusy, recording, recordingStarting, reviewingCapture);
    }

    private void beginZoomInteraction() {
        if (!canAdjustZoom()) return;
        resetFocusInteraction();
        Handler handler = cameraHandler;
        if (handler != null) handler.post(() -> updateRepeatingCropAndFocus(true));
    }

    private void setZoomRatio(float requestedRatio, boolean fromUser) {
        float next = CameraFocusController.clampZoom(requestedRatio, maxDigitalZoom);
        if (Math.abs(next - currentZoomRatio) < 0.001f && fromUser) {
            updateZoomControls();
            return;
        }
        currentZoomRatio = next;
        currentCropRegion = CameraFocusController.zoomCropRegion(
            activeArrayRegion, currentZoomRatio, maxDigitalZoom);
        updateZoomControls();
        Handler handler = cameraHandler;
        if (handler != null) handler.post(() -> updateRepeatingCropAndFocus(false));
    }

    private void updateZoomControls() {
        if (android.os.Looper.myLooper() != android.os.Looper.getMainLooper()) {
            runOnUiThread(this::updateZoomControls);
            return;
        }
        if (binding == null) return;
        String label = String.format(Locale.CHINA, "%.1f×", currentZoomRatio);
        binding.captureZoomValue.setText(label);
        binding.captureZoomSeek.setContentDescription(zoomAccessibilityDescription());
        suppressZoomSeekCallback = true;
        binding.captureZoomSeek.setProgress(CameraFocusController.progressFromZoom(
            currentZoomRatio, CameraFocusController.ZOOM_PROGRESS_MAX, maxDigitalZoom));
        suppressZoomSeekCallback = false;
        boolean zoomAvailable = maxDigitalZoom > 1.001f && canAdjustZoom();
        binding.captureZoomSeek.setEnabled(zoomAvailable);
        binding.captureZoomPanel.setAlpha(zoomAvailable ? 1f : 0.62f);
    }

    private String zoomAccessibilityDescription() {
        if (maxDigitalZoom <= 1.001f) return "此相机不支持焦距调节，当前 1.0 倍";
        return String.format(Locale.CHINA, "相机焦距，当前 %.1f 倍，最大 %.1f 倍",
            currentZoomRatio, maxDigitalZoom);
    }

    private void updateRepeatingCropAndFocus(boolean cancelAutofocus) {
        CameraCaptureSession session = captureSession;
        CaptureRequest.Builder request = previewRequestBuilder;
        if (session == null || request == null) return;
        try {
            request.set(CaptureRequest.SCALER_CROP_REGION, new Rect(currentCropRegion));
            if (cancelAutofocus) {
                request.setTag(null);
                request.set(CaptureRequest.CONTROL_AF_TRIGGER,
                    CaptureRequest.CONTROL_AF_TRIGGER_CANCEL);
                session.capture(request.build(), cameraStateCallback, cameraHandler);
                request.set(CaptureRequest.CONTROL_AF_TRIGGER,
                    CaptureRequest.CONTROL_AF_TRIGGER_IDLE);
                request.set(CaptureRequest.CONTROL_AF_REGIONS, null);
                request.set(CaptureRequest.CONTROL_AE_REGIONS, null);
                if (aeLockAvailable) request.set(CaptureRequest.CONTROL_AE_LOCK, false);
                request.set(CaptureRequest.CONTROL_AF_MODE,
                    recording ? videoAfMode : pictureAfMode);
            }
            session.setRepeatingRequest(request.build(), cameraStateCallback, cameraHandler);
        } catch (CameraAccessException | IllegalArgumentException ignored) { }
    }

    private void releaseFocusLockFromGesture() {
        currentMeteringRegion = null;
        focusRequestGeneration++;
        awaitingFocusGeneration = -1;
        Handler handler = cameraHandler;
        if (handler != null) handler.post(() -> updateRepeatingCropAndFocus(true));
        if (binding != null) {
            binding.captureFocusIndicator.animate().cancel();
            binding.captureFocusIndicator.setVisibility(View.GONE);
            binding.capturePreview.announceForAccessibility("焦点已解锁");
        }
    }

    private void submitTouchFocus(float previewX, float previewY, boolean triggerAf,
                                  boolean retainLock) {
        ActivityInAppCaptureBinding currentBinding = binding;
        Handler handler = cameraHandler;
        if (currentBinding == null || handler == null || !canTouchFocus()) return;
        int previewWidth = currentBinding.capturePreview.getWidth();
        int previewHeight = currentBinding.capturePreview.getHeight();
        if (previewWidth <= 0 || previewHeight <= 0) return;
        int displayRotation = rotationDegrees(getWindowManager().getDefaultDisplay().getRotation());
        MeteringRectangle region = CameraFocusController.meteringRectangle(previewX, previewY,
            previewWidth, previewHeight, currentCropRegion, sensorOrientation, displayRotation,
            lensFacing == CameraCharacteristics.LENS_FACING_FRONT);
        currentMeteringRegion = region;
        int generation = focusRequestGeneration + 1;
        focusRequestGeneration = generation;
        handler.post(() -> applyTouchFocus(region, triggerAf, retainLock, generation));
    }

    private void applyTouchFocus(MeteringRectangle region, boolean triggerAf,
                                 boolean retainLock, int generation) {
        if (generation != focusRequestGeneration) return;
        CameraCaptureSession session = captureSession;
        CaptureRequest.Builder request = previewRequestBuilder;
        if (session == null || request == null || cameraDevice == null) return;
        try {
            if (maxAfRegions > 0) {
                request.set(CaptureRequest.CONTROL_AF_REGIONS,
                    new MeteringRectangle[] { region });
            }
            if (maxAeRegions > 0) {
                request.set(CaptureRequest.CONTROL_AE_REGIONS,
                    new MeteringRectangle[] { region });
            }
            if (aeLockAvailable) request.set(CaptureRequest.CONTROL_AE_LOCK, retainLock);

            request.setTag(null);
            request.set(CaptureRequest.CONTROL_AF_TRIGGER, CaptureRequest.CONTROL_AF_TRIGGER_CANCEL);
            session.capture(request.build(), cameraStateCallback, cameraHandler);
            request.set(CaptureRequest.CONTROL_AF_TRIGGER, CaptureRequest.CONTROL_AF_TRIGGER_IDLE);

            boolean canTriggerAf = touchAfMode != CaptureRequest.CONTROL_AF_MODE_OFF;
            if (triggerAf && canTriggerAf) {
                request.set(CaptureRequest.CONTROL_AF_MODE, touchAfMode);
                request.setTag(new FocusRequestTag(generation));
                request.set(CaptureRequest.CONTROL_AF_TRIGGER, CaptureRequest.CONTROL_AF_TRIGGER_START);
                awaitingFocusGeneration = generation;
                awaitingFocusLocked = retainLock;
                session.capture(request.build(), cameraStateCallback, cameraHandler);
                request.set(CaptureRequest.CONTROL_AF_TRIGGER, CaptureRequest.CONTROL_AF_TRIGGER_IDLE);
            } else {
                request.setTag(null);
                request.set(CaptureRequest.CONTROL_AF_MODE,
                    recording ? videoAfMode : pictureAfMode);
                awaitingFocusGeneration = -1;
            }
            session.setRepeatingRequest(request.build(), cameraStateCallback, cameraHandler);

            if (triggerAf) {
                if (canTriggerAf) {
                    cameraHandler.postDelayed(() -> finishFocusWithoutTerminalState(generation,
                        retainLock), 900L);
                } else {
                    runOnUiThread(() -> {
                        if (binding != null && generation == focusRequestGeneration) {
                            updateFocusIndicator(retainLock ? FOCUS_STATE_LOCKED
                                : FOCUS_STATE_SUCCESS, retainLock);
                        }
                    });
                }
                if (!retainLock) {
                    cameraHandler.postDelayed(() -> releaseTransientFocus(generation),
                        FOCUS_RELEASE_MS);
                }
            }
        } catch (CameraAccessException | IllegalArgumentException exception) {
            runOnUiThread(() -> {
                if (binding == null || generation != focusRequestGeneration) return;
                focusLocked = false;
                updateFocusIndicator(FOCUS_STATE_FAILED, false);
            });
        }
    }

    private void finishFocusWithoutTerminalState(int generation, boolean retainLock) {
        if (generation != focusRequestGeneration || awaitingFocusGeneration != generation) return;
        awaitingFocusGeneration = -1;
        runOnUiThread(() -> {
            if (binding == null || generation != focusRequestGeneration) return;
            updateFocusIndicator(retainLock ? FOCUS_STATE_LOCKED : FOCUS_STATE_SUCCESS,
                retainLock);
        });
    }

    private void releaseTransientFocus(int generation) {
        if (generation != focusRequestGeneration || focusLocked) return;
        CameraCaptureSession session = captureSession;
        CaptureRequest.Builder request = previewRequestBuilder;
        if (session == null || request == null) return;
        try {
            request.setTag(null);
            request.set(CaptureRequest.CONTROL_AF_TRIGGER, CaptureRequest.CONTROL_AF_TRIGGER_CANCEL);
            session.capture(request.build(), cameraStateCallback, cameraHandler);
            request.set(CaptureRequest.CONTROL_AF_TRIGGER, CaptureRequest.CONTROL_AF_TRIGGER_IDLE);
            request.set(CaptureRequest.CONTROL_AF_MODE,
                recording ? videoAfMode : pictureAfMode);
            request.set(CaptureRequest.CONTROL_AF_REGIONS, null);
            request.set(CaptureRequest.CONTROL_AE_REGIONS, null);
            if (aeLockAvailable) request.set(CaptureRequest.CONTROL_AE_LOCK, false);
            session.setRepeatingRequest(request.build(), cameraStateCallback, cameraHandler);
            currentMeteringRegion = null;
            runOnUiThread(() -> {
                if (binding != null && generation == focusRequestGeneration && !focusLocked) {
                    hideFocusIndicator();
                }
            });
        } catch (CameraAccessException | IllegalArgumentException ignored) { }
    }

    private void positionFocusIndicator(float previewX, float previewY, boolean animateIn) {
        if (binding == null) return;
        View preview = binding.capturePreview;
        View indicator = binding.captureFocusIndicator;
        int indicatorWidth = indicator.getWidth() > 0 ? indicator.getWidth() : dp(104);
        int indicatorHeight = indicator.getHeight() > 0 ? indicator.getHeight() : dp(116);
        int frameHeight = dp(76);
        float left = clamp(previewX - indicatorWidth / 2f, 0f,
            Math.max(0f, preview.getWidth() - indicatorWidth));
        float top = clamp(previewY - frameHeight / 2f, 0f,
            Math.max(0f, preview.getHeight() - indicatorHeight));
        indicator.animate().cancel();
        indicator.setX(left);
        indicator.setY(top);
        indicator.setAlpha(1f);
        indicator.setVisibility(View.VISIBLE);
        if (animateIn) {
            indicator.setScaleX(0.74f);
            indicator.setScaleY(0.74f);
            indicator.animate().scaleX(1f).scaleY(1f).setDuration(150L).start();
        } else {
            indicator.setScaleX(1f);
            indicator.setScaleY(1f);
        }
    }

    private void updateFocusIndicator(int state, boolean announceLock) {
        if (binding == null) return;
        if (state == FOCUS_STATE_LOCKED || state == FOCUS_STATE_LOCKING) {
            binding.captureFocusFrame.setBackgroundResource(R.drawable.bg_capture_focus_frame_locked);
            binding.captureFocusStatus.setText(state == FOCUS_STATE_LOCKED ? "焦点已锁定" : "锁定中");
        } else if (state == FOCUS_STATE_SUCCESS) {
            binding.captureFocusFrame.setBackgroundResource(R.drawable.bg_capture_focus_frame_success);
            binding.captureFocusStatus.setText("已聚焦");
        } else if (state == FOCUS_STATE_FAILED) {
            binding.captureFocusFrame.setBackgroundResource(R.drawable.bg_capture_focus_frame_failed);
            binding.captureFocusStatus.setText("未能聚焦");
        } else {
            binding.captureFocusFrame.setBackgroundResource(R.drawable.bg_capture_focus_frame);
            binding.captureFocusStatus.setText(state == FOCUS_STATE_FOLLOWING ? "跟随聚焦" : "聚焦中");
        }
        binding.captureFocusIndicator.setVisibility(View.VISIBLE);
        if (announceLock && state == FOCUS_STATE_LOCKED) {
            binding.capturePreview.announceForAccessibility("焦点已锁定");
        }
    }

    private void hideFocusIndicator() {
        if (binding == null || focusLocked) return;
        View indicator = binding.captureFocusIndicator;
        indicator.animate().cancel();
        indicator.animate().alpha(0f).setDuration(140L).withEndAction(() -> {
            if (binding != null && !focusLocked && !focusGesture.isActive()) {
                binding.captureFocusIndicator.setVisibility(View.GONE);
            }
        }).start();
    }

    private void resetFocusInteraction() {
        mainHandler.removeCallbacks(lockFocusAfterHold);
        mainHandler.removeCallbacks(submitFollowFocus);
        followFocusPosted = false;
        focusGesture.cancel();
        focusWasLockedAtGestureStart = false;
        focusLocked = false;
        currentMeteringRegion = null;
        focusRequestGeneration++;
        awaitingFocusGeneration = -1;
        if (binding != null) {
            binding.captureFocusIndicator.animate().cancel();
            binding.captureFocusIndicator.setAlpha(1f);
            binding.captureFocusIndicator.setVisibility(View.GONE);
        }
    }

    private void openCameraWhenReady() {
        if (reviewingCapture || !surfaceReady || cameraDevice != null || openingCamera
            || !hasPermission(Manifest.permission.CAMERA)) return;
        if (cameraHandler == null) return;
        CameraManager manager = (CameraManager) getSystemService(Context.CAMERA_SERVICE);
        try {
            cameraId = selectCameraId(manager, lensFacing);
            if (cameraId.isEmpty()) {
                Toast.makeText(this, "设备没有可用摄像头", Toast.LENGTH_LONG).show();
                finish();
                return;
            }
            CameraCharacteristics characteristics = manager.getCameraCharacteristics(cameraId);
            Integer actualFacing = characteristics.get(CameraCharacteristics.LENS_FACING);
            if (actualFacing != null) lensFacing = actualFacing;
            Rect activeArray = characteristics.get(CameraCharacteristics.SENSOR_INFO_ACTIVE_ARRAY_SIZE);
            activeArrayRegion = activeArray == null || activeArray.isEmpty()
                ? new Rect(0, 0, 1, 1) : new Rect(activeArray);
            maxDigitalZoom = CameraFocusController.maxZoom(
                characteristics.get(CameraCharacteristics.SCALER_AVAILABLE_MAX_DIGITAL_ZOOM));
            currentZoomRatio = CameraFocusController.clampZoom(currentZoomRatio, maxDigitalZoom);
            currentCropRegion = CameraFocusController.zoomCropRegion(
                activeArrayRegion, currentZoomRatio, maxDigitalZoom);
            runOnUiThread(this::updateZoomControls);
            Integer orientation = characteristics.get(CameraCharacteristics.SENSOR_ORIENTATION);
            sensorOrientation = orientation == null ? 90 : orientation;
            Integer afRegions = characteristics.get(CameraCharacteristics.CONTROL_MAX_REGIONS_AF);
            Integer aeRegions = characteristics.get(CameraCharacteristics.CONTROL_MAX_REGIONS_AE);
            maxAfRegions = afRegions == null ? 0 : Math.max(0, afRegions);
            maxAeRegions = aeRegions == null ? 0 : Math.max(0, aeRegions);
            Boolean lockAvailable = characteristics.get(CameraCharacteristics.CONTROL_AE_LOCK_AVAILABLE);
            aeLockAvailable = Boolean.TRUE.equals(lockAvailable);
            int[] availableAfModes = characteristics.get(CameraCharacteristics.CONTROL_AF_AVAILABLE_MODES);
            touchAfMode = CameraFocusController.touchAfMode(availableAfModes);
            pictureAfMode = CameraFocusController.continuousAfMode(availableAfModes, false);
            videoAfMode = CameraFocusController.continuousAfMode(availableAfModes, true);
            StreamConfigurationMap map = characteristics.get(CameraCharacteristics.SCALER_STREAM_CONFIGURATION_MAP);
            if (map == null) throw new CameraAccessException(CameraAccessException.CAMERA_ERROR, "相机不支持预览");
            videoSize = chooseBoundedSize(map.getOutputSizes(MediaRecorder.class),
                MAX_VIDEO_WIDTH, MAX_VIDEO_HEIGHT, 16f / 9f);
            if (videoSize == null) videoSize = new android.util.Size(640, 480);
            previewSize = videoSize;
            android.util.Size photoSize = chooseBoundedSize(map.getOutputSizes(ImageFormat.JPEG),
                MAX_PHOTO_WIDTH, MAX_PHOTO_HEIGHT, ratio(videoSize));
            if (photoSize == null) photoSize = new android.util.Size(1280, 960);
            closeImageReader();
            imageReader = ImageReader.newInstance(photoSize.getWidth(), photoSize.getHeight(),
                ImageFormat.JPEG, 2);
            imageReader.setOnImageAvailableListener(this::savePhotoImage, cameraHandler);
            binding.capturePreview.getHolder().setFixedSize(previewSize.getWidth(), previewSize.getHeight());
            openingCamera = true;
            manager.openCamera(cameraId, new CameraDevice.StateCallback() {
                @Override public void onOpened(@NonNull CameraDevice camera) {
                    openingCamera = false;
                    cameraDevice = camera;
                    startPreview();
                }

                @Override public void onDisconnected(@NonNull CameraDevice camera) {
                    openingCamera = false;
                    camera.close();
                    cameraDevice = null;
                }

                @Override public void onError(@NonNull CameraDevice camera, int error) {
                    openingCamera = false;
                    camera.close();
                    cameraDevice = null;
                    runOnUiThread(() -> {
                        Toast.makeText(InAppCaptureActivity.this, "相机启动失败（" + error + "）", Toast.LENGTH_LONG).show();
                        finish();
                    });
                }
            }, cameraHandler);
        } catch (CameraAccessException | SecurityException exception) {
            openingCamera = false;
            Toast.makeText(this, "无法打开相机：" + exception.getMessage(), Toast.LENGTH_LONG).show();
        }
    }

    private String selectCameraId(CameraManager manager, int requestedFacing) throws CameraAccessException {
        String fallback = "";
        for (String id : manager.getCameraIdList()) {
            if (fallback.isEmpty()) fallback = id;
            Integer facing = manager.getCameraCharacteristics(id).get(CameraCharacteristics.LENS_FACING);
            if (facing != null && facing == requestedFacing) return id;
        }
        return fallback;
    }

    private void startPreview() {
        CameraDevice camera = cameraDevice;
        ImageReader reader = imageReader;
        Surface surface = binding == null ? null : binding.capturePreview.getHolder().getSurface();
        if (reviewingCapture || camera == null || reader == null || surface == null
            || !surface.isValid() || recordingStarting) return;
        closeCaptureSession();
        try {
            CaptureRequest.Builder request = camera.createCaptureRequest(CameraDevice.TEMPLATE_PREVIEW);
            request.addTarget(surface);
            request.setTag(null);
            request.set(CaptureRequest.CONTROL_AF_MODE, pictureAfMode);
            request.set(CaptureRequest.SCALER_CROP_REGION, new Rect(currentCropRegion));
            if (aeLockAvailable) request.set(CaptureRequest.CONTROL_AE_LOCK, false);
            previewRequestBuilder = request;
            camera.createCaptureSession(Arrays.asList(surface, reader.getSurface()),
                new CameraCaptureSession.StateCallback() {
                    @Override public void onConfigured(@NonNull CameraCaptureSession session) {
                        if (cameraDevice == null) { session.close(); return; }
                        captureSession = session;
                        try { session.setRepeatingRequest(request.build(), cameraStateCallback, cameraHandler); }
                        catch (CameraAccessException ignored) { }
                        runOnUiThread(() -> {
                            setCaptureControlsEnabled(true);
                            resetFocusInteraction();
                            updateZoomControls();
                        });
                    }

                    @Override public void onConfigureFailed(@NonNull CameraCaptureSession session) {
                        runOnUiThread(() -> Toast.makeText(InAppCaptureActivity.this,
                            "相机预览启动失败", Toast.LENGTH_LONG).show());
                    }
                }, cameraHandler);
        } catch (CameraAccessException exception) {
            Toast.makeText(this, "相机预览启动失败：" + exception.getMessage(), Toast.LENGTH_LONG).show();
        }
    }

    private void takePhoto() {
        CameraDevice camera = cameraDevice;
        CameraCaptureSession session = captureSession;
        ImageReader reader = imageReader;
        if (camera == null || session == null || reader == null || captureBusy) return;
        captureBusy = true;
        setCaptureControlsEnabled(false);
        try {
            CaptureRequest.Builder request = camera.createCaptureRequest(CameraDevice.TEMPLATE_STILL_CAPTURE);
            request.addTarget(reader.getSurface());
            request.set(CaptureRequest.SCALER_CROP_REGION, new Rect(currentCropRegion));
            applyCurrentFocusToCapture(request, false);
            request.set(CaptureRequest.JPEG_ORIENTATION, captureOrientation());
            session.stopRepeating();
            session.capture(request.build(), new CameraCaptureSession.CaptureCallback() {
                @Override public void onCaptureCompleted(@NonNull CameraCaptureSession captureSession,
                                                         @NonNull CaptureRequest request,
                                                         @NonNull TotalCaptureResult result) { }
            }, cameraHandler);
        } catch (CameraAccessException exception) {
            captureBusy = false;
            setCaptureControlsEnabled(true);
            Toast.makeText(this, "拍照失败：" + exception.getMessage(), Toast.LENGTH_LONG).show();
            startPreview();
        }
    }

    private void applyCurrentFocusToCapture(CaptureRequest.Builder request, boolean video) {
        request.set(CaptureRequest.SCALER_CROP_REGION, new Rect(currentCropRegion));
        MeteringRectangle region = currentMeteringRegion;
        if (region != null) {
            request.set(CaptureRequest.CONTROL_AF_MODE,
                focusLocked || !video ? touchAfMode : videoAfMode);
            if (maxAfRegions > 0) {
                request.set(CaptureRequest.CONTROL_AF_REGIONS,
                    new MeteringRectangle[] { region });
            }
            if (maxAeRegions > 0) {
                request.set(CaptureRequest.CONTROL_AE_REGIONS,
                    new MeteringRectangle[] { region });
            }
            if (aeLockAvailable) request.set(CaptureRequest.CONTROL_AE_LOCK, focusLocked);
        } else {
            request.set(CaptureRequest.CONTROL_AF_MODE, video ? videoAfMode : pictureAfMode);
            if (aeLockAvailable) request.set(CaptureRequest.CONTROL_AE_LOCK, false);
        }
    }

    private void savePhotoImage(ImageReader reader) {
        File output = null;
        try (Image image = reader.acquireLatestImage()) {
            if (image == null) {
                runOnUiThread(() -> {
                    captureBusy = false;
                    setCaptureControlsEnabled(true);
                });
                if (cameraHandler != null) cameraHandler.post(this::startPreview);
                return;
            }
            ByteBuffer buffer = image.getPlanes()[0].getBuffer();
            byte[] bytes = new byte[buffer.remaining()];
            buffer.get(bytes);
            output = createCaptureFile(false);
            try (FileOutputStream stream = new FileOutputStream(output)) { stream.write(bytes); }
            completeCapture(output, false);
        } catch (IOException | RuntimeException exception) {
            deleteManagedCapture(output);
            runOnUiThread(() -> {
                captureBusy = false;
                setCaptureControlsEnabled(true);
                Toast.makeText(this, "照片保存失败：" + exception.getMessage(), Toast.LENGTH_LONG).show();
            });
            if (cameraHandler != null) cameraHandler.post(this::startPreview);
        }
    }

    private void beginVideoRecording() {
        if (recording || recordingStarting || cameraDevice == null || cameraHandler == null) return;
        mainHandler.removeCallbacks(lockFocusAfterHold);
        mainHandler.removeCallbacks(submitFollowFocus);
        followFocusPosted = false;
        focusGesture.cancel();
        if (!focusLocked) hideFocusIndicator();
        captureBusy = true;
        recordingStarting = true;
        // Keep the current gesture target enabled so the held pointer can still deliver ACTION_UP.
        // A second press is already rejected by captureBusy/recordingStarting in handleShutterTouch.
        if (binding != null) {
            binding.captureShutter.setEnabled(true);
            binding.captureSwitchCamera.setEnabled(false);
            binding.captureClose.setEnabled(false);
            binding.captureZoomSeek.setEnabled(false);
        }
        cameraHandler.post(() -> {
            CameraDevice camera = cameraDevice;
            Surface preview = binding == null ? null : binding.capturePreview.getHolder().getSurface();
            if (camera == null || preview == null || !preview.isValid()) {
                failRecording("相机预览不可用");
                return;
            }
            try {
                closeCaptureSession();
                captureFile = createCaptureFile(true);
                prepareMediaRecorder(captureFile);
                Surface recorderSurface = mediaRecorder.getSurface();
                CaptureRequest.Builder request = camera.createCaptureRequest(CameraDevice.TEMPLATE_RECORD);
                request.addTarget(preview);
                request.addTarget(recorderSurface);
                request.set(CaptureRequest.SCALER_CROP_REGION, new Rect(currentCropRegion));
                applyCurrentFocusToCapture(request, true);
                camera.createCaptureSession(Arrays.asList(preview, recorderSurface),
                    new CameraCaptureSession.StateCallback() {
                        @Override public void onConfigured(@NonNull CameraCaptureSession session) {
                            if (cameraDevice == null || !recordingRequested) {
                                session.close();
                                releaseRecorder(true);
                                recordingStarting = false;
                                captureBusy = false;
                                startPreview();
                                runOnUiThread(() -> setCaptureControlsEnabled(true));
                                return;
                            }
                            captureSession = session;
                            previewRequestBuilder = request;
                            try {
                                session.setRepeatingRequest(request.build(), cameraStateCallback, cameraHandler);
                                mediaRecorder.start();
                                recordingStartedAt = SystemClock.elapsedRealtime();
                                recording = true;
                                recordingStarting = false;
                                runOnUiThread(InAppCaptureActivity.this::showRecordingState);
                            } catch (CameraAccessException | RuntimeException exception) {
                                failRecording(exception.getMessage());
                            }
                        }

                        @Override public void onConfigureFailed(@NonNull CameraCaptureSession session) {
                            failRecording("录像通道启动失败");
                        }
                    }, cameraHandler);
            } catch (IOException | CameraAccessException | RuntimeException exception) {
                failRecording(exception.getMessage());
            }
        });
    }

    @SuppressWarnings("deprecation")
    private void prepareMediaRecorder(File output) throws IOException {
        MediaRecorder recorder = new MediaRecorder();
        boolean withAudio = hasPermission(Manifest.permission.RECORD_AUDIO);
        if (withAudio) recorder.setAudioSource(MediaRecorder.AudioSource.MIC);
        recorder.setVideoSource(MediaRecorder.VideoSource.SURFACE);
        recorder.setOutputFormat(MediaRecorder.OutputFormat.MPEG_4);
        recorder.setOutputFile(output.getAbsolutePath());
        recorder.setVideoEncodingBitRate(4_000_000);
        recorder.setVideoFrameRate(30);
        recorder.setVideoSize(videoSize.getWidth(), videoSize.getHeight());
        recorder.setVideoEncoder(MediaRecorder.VideoEncoder.H264);
        if (withAudio) {
            recorder.setAudioEncodingBitRate(96_000);
            recorder.setAudioSamplingRate(44_100);
            recorder.setAudioEncoder(MediaRecorder.AudioEncoder.AAC);
        }
        recorder.setOrientationHint(captureOrientation());
        recorder.setMaxDuration((int) MAX_VIDEO_MS);
        recorder.setOnInfoListener((value, what, extra) -> {
            if (what == MediaRecorder.MEDIA_RECORDER_INFO_MAX_DURATION_REACHED) {
                runOnUiThread(() -> {
                    recordingRequested = false;
                    stopVideoRecording(true);
                });
            }
        });
        recorder.prepare();
        mediaRecorder = recorder;
    }

    private void showRecordingState() {
        if (binding == null) return;
        binding.recordingTimer.setVisibility(View.VISIBLE);
        binding.captureHint.setText("松手结束录像 · 最长 60 秒\n录制中仍可点按、拖动、长按焦点或调焦距");
        binding.captureShutterCore.setBackgroundResource(R.drawable.bg_capture_shutter_recording);
        binding.captureShutter.setEnabled(true);
        binding.captureSwitchCamera.setEnabled(false);
        binding.captureClose.setEnabled(false);
        updateZoomControls();
        mainHandler.removeCallbacks(recordingTicker);
        mainHandler.post(recordingTicker);
        if (!recordingRequested) stopVideoRecording(false);
    }

    private void stopVideoRecording(boolean reachedLimit) {
        if (!recording) return;
        recordingRequested = false;
        recording = false;
        captureBusy = true;
        if (binding != null) binding.captureZoomSeek.setEnabled(false);
        mainHandler.removeCallbacks(recordingTicker);
        long duration = Math.max(0L, SystemClock.elapsedRealtime() - recordingStartedAt);
        Handler handler = cameraHandler;
        if (handler == null) {
            releaseRecorder(true);
            recordingStarting = false;
            captureBusy = false;
            runOnUiThread(this::resetRecordingUi);
            return;
        }
        handler.post(() -> {
            boolean success = true;
            try {
                if (captureSession != null) {
                    captureSession.stopRepeating();
                    captureSession.abortCaptures();
                }
                if (mediaRecorder != null) mediaRecorder.stop();
            } catch (CameraAccessException | RuntimeException exception) {
                success = false;
            }
            File completed = captureFile;
            releaseRecorder(!success || duration < MIN_VIDEO_MS);
            recordingStarting = false;
            if (success && duration >= MIN_VIDEO_MS && completed != null && completed.length() > 0L) {
                completeCapture(completed, true);
            } else {
                runOnUiThread(() -> {
                    captureBusy = false;
                    resetRecordingUi();
                    Toast.makeText(this, "录像时间太短，请重新长按拍摄", Toast.LENGTH_SHORT).show();
                });
                startPreview();
            }
        });
    }

    private void failRecording(String message) {
        releaseRecorder(true);
        recording = false;
        recordingStarting = false;
        recordingRequested = false;
        captureBusy = false;
        runOnUiThread(() -> {
            resetRecordingUi();
            Toast.makeText(this, "录像失败：" + (message == null ? "请重试" : message), Toast.LENGTH_LONG).show();
        });
        startPreview();
    }

    private void resetRecordingUi() {
        if (binding == null) return;
        binding.recordingTimer.setVisibility(View.GONE);
        binding.recordingTimer.setText("00:00 / 01:00");
        binding.captureHint.setText(defaultCaptureHint());
        binding.captureShutterCore.setBackgroundResource(R.drawable.bg_capture_shutter_photo);
        setCaptureControlsEnabled(true);
    }

    private String defaultCaptureHint() {
        return (hasPermission(Manifest.permission.RECORD_AUDIO)
            ? "轻触拍照 · 长按录像（最多 60 秒）"
            : "轻触拍照 · 长按无声录像（最多 60 秒）")
            + "\n点按聚焦 · 拖动跟焦 · 长按锁焦/解锁 · 双指或滑杆调焦距";
    }

    private void completeCapture(File file, boolean video) {
        if (!getLifecycle().getCurrentState().isAtLeast(
            androidx.lifecycle.Lifecycle.State.RESUMED)) {
            captureBusy = false;
            deleteManagedCapture(file);
            return;
        }
        if (!isManagedCaptureFile(file) || !file.exists() || file.length() <= 0L) {
            deleteManagedCapture(file);
            runOnUiThread(() -> {
                captureBusy = false;
                resetRecordingUi();
                Toast.makeText(this, "拍摄成品无效，请重新拍摄", Toast.LENGTH_LONG).show();
            });
            if (cameraHandler != null) cameraHandler.post(this::startPreview);
            return;
        }
        pendingCaptureFile = file;
        pendingCaptureVideo = video;
        reviewingCapture = true;
        Handler handler = cameraHandler;
        Runnable prepareReview = () -> {
            closeCamera();
            runOnUiThread(() -> showCapturedPreview(file, video));
        };
        if (handler == null) prepareReview.run();
        else handler.post(prepareReview);
    }

    private void showCapturedPreview(File file, boolean video) {
        if (deliveringResult || isFinishing() || isDestroyed()) {
            pendingCaptureFile = null;
            reviewingCapture = false;
            // A replacement Activity may already hold this path in saved state during rotation.
            // Leave it in place for that instance; stale-cache pruning remains the crash fallback.
            if (!isChangingConfigurations()) deleteManagedCapture(file);
            return;
        }
        File previous = pendingCaptureFile;
        if (previous != null && !previous.equals(file)) deleteManagedCapture(previous);
        pendingCaptureFile = file;
        pendingCaptureVideo = video;
        reviewingCapture = true;
        captureBusy = false;
        recording = false;
        recordingStarting = false;
        recordingRequested = false;
        resetFocusInteraction();
        stopReviewPlayback();
        if (binding == null) {
            pendingCaptureFile = null;
            reviewingCapture = false;
            if (!isChangingConfigurations()) deleteManagedCapture(file);
            return;
        }
        binding.captureReviewContainer.setVisibility(View.VISIBLE);
        binding.captureReviewActions.setVisibility(View.VISIBLE);
        binding.captureBottomBar.setVisibility(View.GONE);
        binding.captureSwitchCamera.setVisibility(View.INVISIBLE);
        binding.recordingTimer.setVisibility(View.GONE);
        binding.captureFocusIndicator.setVisibility(View.GONE);
        binding.captureClose.setEnabled(true);
        binding.captureClose.setContentDescription("取消当前成品并退出拍摄");
        binding.captureReviewLabel.setText(video ? "请确认视频成品" : "请确认照片成品");
        binding.captureReviewImage.setVisibility(video ? View.GONE : View.VISIBLE);
        binding.captureReviewVideo.setVisibility(video ? View.VISIBLE : View.GONE);
        if (video) {
            binding.captureReviewImage.setImageDrawable(null);
            binding.captureReviewVideo.setVideoPath(file.getAbsolutePath());
            binding.captureReviewVideo.setOnPreparedListener(player -> {
                player.setLooping(true);
                if (binding != null && reviewingCapture) binding.captureReviewVideo.start();
            });
        } else {
            binding.captureReviewImage.setImageURI(Uri.fromFile(file));
        }
        binding.captureReviewContainer.announceForAccessibility(
            video ? "视频拍摄完成，请确认或重拍" : "照片拍摄完成，请确认或重拍");
        updateZoomControls();
    }

    private boolean restoreCapturedPreview(Bundle state) {
        if (state == null || !state.getBoolean(STATE_REVIEWING_CAPTURE, false)) return false;
        String path = state.getString(STATE_CAPTURE_PATH, "");
        File restored = path.isEmpty() ? null : new File(path);
        if (!isManagedCaptureFile(restored) || restored == null || !restored.exists()
            || restored.length() <= 0L) {
            deleteManagedCapture(restored);
            return false;
        }
        showCapturedPreview(restored, state.getBoolean(STATE_CAPTURE_VIDEO, false));
        return reviewingCapture;
    }

    private void confirmCapturedPreview() {
        File file = pendingCaptureFile;
        if (!reviewingCapture || deliveringResult || !isManagedCaptureFile(file)
            || file == null || !file.exists() || file.length() <= 0L) {
            Toast.makeText(this, "拍摄成品已失效，请重新拍摄", Toast.LENGTH_LONG).show();
            discardCapturedPreviewAndResume();
            return;
        }
        deliveringResult = true;
        stopReviewPlayback();
        Uri uri = FileProvider.getUriForFile(this,
            getPackageName() + ".capture-files", file);
        Intent result = new Intent()
            .putExtra(EXTRA_CAPTURE_URI, uri)
            .putExtra(EXTRA_CAPTURE_VIDEO, pendingCaptureVideo)
            .setData(uri)
            .addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION);
        result.setClipData(android.content.ClipData.newRawUri("拍摄成品", uri));
        pendingCaptureFile = null;
        reviewingCapture = false;
        setResult(RESULT_OK, result);
        finish();
    }

    private void discardCapturedPreviewAndResume() {
        if (deliveringResult) return;
        stopReviewPlayback();
        deletePendingCapture();
        captureBusy = false;
        recording = false;
        recordingStarting = false;
        recordingRequested = false;
        showLiveCaptureUi();
        startCameraThread();
        openCameraWhenReady();
    }

    private void showLiveCaptureUi() {
        if (binding == null) return;
        binding.captureReviewContainer.setVisibility(View.GONE);
        binding.captureReviewActions.setVisibility(View.GONE);
        binding.captureReviewImage.setImageDrawable(null);
        binding.captureReviewImage.setVisibility(View.GONE);
        binding.captureReviewVideo.setVisibility(View.GONE);
        binding.captureBottomBar.setVisibility(View.VISIBLE);
        binding.captureSwitchCamera.setVisibility(View.VISIBLE);
        binding.captureClose.setContentDescription("取消拍摄");
        resetRecordingUi();
        updateZoomControls();
    }

    private void resumeReviewPlayback() {
        if (binding != null && reviewingCapture && pendingCaptureVideo
            && binding.captureReviewVideo.getVisibility() == View.VISIBLE) {
            binding.captureReviewVideo.start();
        }
    }

    private void stopReviewPlayback() {
        if (binding == null) return;
        try { binding.captureReviewVideo.stopPlayback(); }
        catch (RuntimeException ignored) { }
    }

    private void deletePendingCapture() {
        File pending = pendingCaptureFile;
        pendingCaptureFile = null;
        pendingCaptureVideo = false;
        reviewingCapture = false;
        deleteManagedCapture(pending);
    }

    private void deleteManagedCapture(File file) {
        if (!isManagedCaptureFile(file) || file == null || !file.exists()) return;
        //noinspection ResultOfMethodCallIgnored
        file.delete();
    }

    private boolean isManagedCaptureFile(File file) {
        if (file == null) return false;
        try {
            File directory = new File(getCacheDir(), "captures").getCanonicalFile();
            File candidate = file.getCanonicalFile();
            return directory.equals(candidate.getParentFile())
                && (candidate.getName().startsWith("app_photo_")
                    || candidate.getName().startsWith("app_video_"));
        } catch (IOException exception) {
            return false;
        }
    }

    private void pruneStaleCaptureFiles() {
        File directory = new File(getCacheDir(), "captures");
        File[] files = directory.listFiles();
        if (files == null) return;
        long cutoff = System.currentTimeMillis() - STALE_CAPTURE_AGE_MS;
        for (File file : files) {
            if (file != null && file.lastModified() < cutoff && isManagedCaptureFile(file)) {
                deleteManagedCapture(file);
            }
        }
    }

    private void switchCamera() {
        if (recording || recordingStarting || captureBusy) return;
        resetFocusInteraction();
        currentZoomRatio = 1f;
        maxDigitalZoom = 1f;
        currentCropRegion = new Rect(activeArrayRegion);
        updateZoomControls();
        lensFacing = lensFacing == CameraCharacteristics.LENS_FACING_BACK
            ? CameraCharacteristics.LENS_FACING_FRONT
            : CameraCharacteristics.LENS_FACING_BACK;
        closeCamera();
        openCameraWhenReady();
    }

    private void cancelCapture() {
        if (deliveringResult) return;
        deliveringResult = true;
        recordingRequested = false;
        mainHandler.removeCallbacks(beginVideoAfterHold);
        mainHandler.removeCallbacks(recordingTicker);
        resetFocusInteraction();
        stopReviewPlayback();
        deletePendingCapture();
        if (cameraHandler == null) {
            setResult(RESULT_CANCELED);
            finish();
            return;
        }
        cameraHandler.post(() -> {
            if (recording && mediaRecorder != null) {
                try { mediaRecorder.stop(); } catch (RuntimeException ignored) { }
            }
            releaseRecorder(true);
            runOnUiThread(() -> {
                setResult(RESULT_CANCELED);
                finish();
            });
        });
    }

    private File createCaptureFile(boolean video) throws IOException {
        File directory = new File(getCacheDir(), "captures");
        if (!directory.exists() && !directory.mkdirs()) throw new IOException("无法创建拍摄缓存目录");
        return File.createTempFile(video ? "app_video_" : "app_photo_", video ? ".mp4" : ".jpg", directory);
    }

    private void releaseRecorder(boolean deleteOutput) {
        MediaRecorder recorder = mediaRecorder;
        mediaRecorder = null;
        if (recorder != null) {
            try { recorder.reset(); } catch (RuntimeException ignored) { }
            try { recorder.release(); } catch (RuntimeException ignored) { }
        }
        if (deleteOutput && captureFile != null && captureFile.exists()) {
            //noinspection ResultOfMethodCallIgnored
            captureFile.delete();
        }
        captureFile = null;
    }

    private void setCaptureControlsEnabled(boolean enabled) {
        if (binding == null) return;
        boolean liveEnabled = enabled && !reviewingCapture;
        binding.captureShutter.setEnabled(liveEnabled);
        binding.captureSwitchCamera.setEnabled(liveEnabled && !recording && !recordingStarting);
        binding.captureClose.setEnabled(reviewingCapture || (enabled && !recording && !recordingStarting));
        binding.captureZoomSeek.setEnabled(liveEnabled && maxDigitalZoom > 1.001f
            && !recordingStarting);
    }

    private int captureOrientation() {
        try {
            CameraManager manager = (CameraManager) getSystemService(Context.CAMERA_SERVICE);
            CameraCharacteristics characteristics = manager.getCameraCharacteristics(cameraId);
            Integer sensor = characteristics.get(CameraCharacteristics.SENSOR_ORIENTATION);
            Integer facing = characteristics.get(CameraCharacteristics.LENS_FACING);
            int device = rotationDegrees(getWindowManager().getDefaultDisplay().getRotation());
            int sensorDegrees = sensor == null ? 90 : sensor;
            return facing != null && facing == CameraCharacteristics.LENS_FACING_FRONT
                ? (sensorDegrees + device) % 360
                : (sensorDegrees - device + 360) % 360;
        } catch (CameraAccessException exception) {
            return lensFacing == CameraCharacteristics.LENS_FACING_FRONT ? 270 : 90;
        }
    }

    private static int rotationDegrees(int rotation) {
        if (rotation == Surface.ROTATION_90) return 90;
        if (rotation == Surface.ROTATION_180) return 180;
        if (rotation == Surface.ROTATION_270) return 270;
        return 0;
    }

    static android.util.Size chooseBoundedSize(android.util.Size[] values,
                                                int maxWidth, int maxHeight,
                                                float targetRatio) {
        if (values == null || values.length == 0) return null;
        List<android.util.Size> bounded = new ArrayList<>();
        for (android.util.Size value : values) {
            int longSide = Math.max(value.getWidth(), value.getHeight());
            int shortSide = Math.min(value.getWidth(), value.getHeight());
            if (longSide <= Math.max(maxWidth, maxHeight) && shortSide <= Math.min(maxWidth, maxHeight)) {
                bounded.add(value);
            }
        }
        Comparator<android.util.Size> aspect = Comparator.comparingDouble(
            value -> Math.abs(ratio(value) - targetRatio));
        if (bounded.isEmpty()) {
            // Some camera HALs only publish modes above our preferred ceiling. In that case use
            // the smallest viable mode instead of accidentally choosing the sensor's maximum.
            return Collections.min(Arrays.asList(values), aspect.thenComparingLong(
                value -> (long) value.getWidth() * value.getHeight()));
        }
        return Collections.min(bounded, aspect.thenComparingLong(
            value -> -((long) value.getWidth() * value.getHeight())));
    }

    private static float ratio(android.util.Size value) {
        int longSide = Math.max(value.getWidth(), value.getHeight());
        int shortSide = Math.max(1, Math.min(value.getWidth(), value.getHeight()));
        return longSide / (float) shortSide;
    }

    private void closeCaptureSession() {
        CameraCaptureSession session = captureSession;
        captureSession = null;
        previewRequestBuilder = null;
        awaitingFocusGeneration = -1;
        if (session != null) {
            try { session.close(); } catch (RuntimeException ignored) { }
        }
    }

    private void closeImageReader() {
        ImageReader reader = imageReader;
        imageReader = null;
        if (reader != null) reader.close();
    }

    private void closeCamera() {
        closeCaptureSession();
        if (recording && mediaRecorder != null) {
            try { mediaRecorder.stop(); } catch (RuntimeException ignored) { }
        }
        recording = false;
        recordingStarting = false;
        recordingRequested = false;
        captureBusy = false;
        longPressTriggered = false;
        releaseRecorder(true);
        CameraDevice camera = cameraDevice;
        cameraDevice = null;
        if (camera != null) camera.close();
        closeImageReader();
        openingCamera = false;
    }

    private void startCameraThread() {
        if (cameraThread != null) return;
        cameraThread = new HandlerThread("YiyunyingAppCamera");
        cameraThread.start();
        cameraHandler = new Handler(cameraThread.getLooper());
    }

    private void stopCameraThread() {
        HandlerThread thread = cameraThread;
        cameraThread = null;
        cameraHandler = null;
        if (thread == null) return;
        thread.quitSafely();
        try { thread.join(1200L); }
        catch (InterruptedException exception) { Thread.currentThread().interrupt(); }
    }

    private int dp(int value) {
        return Math.round(value * getResources().getDisplayMetrics().density);
    }

    private static float clamp(float value, float minimum, float maximum) {
        return Math.max(minimum, Math.min(maximum, value));
    }

    private static float distance(float firstX, float firstY, float secondX, float secondY) {
        return (float) Math.hypot(secondX - firstX, secondY - firstY);
    }
}
