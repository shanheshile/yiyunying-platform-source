package xyz.jjmxg.yiyunying.ui.chat;

import android.Manifest;
import android.content.Context;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.graphics.ImageFormat;
import android.hardware.camera2.CameraAccessException;
import android.hardware.camera2.CameraCaptureSession;
import android.hardware.camera2.CameraCharacteristics;
import android.hardware.camera2.CameraDevice;
import android.hardware.camera2.CameraManager;
import android.hardware.camera2.CaptureRequest;
import android.hardware.camera2.TotalCaptureResult;
import android.hardware.camera2.params.StreamConfigurationMap;
import android.media.Image;
import android.media.ImageReader;
import android.media.MediaRecorder;
import android.net.Uri;
import android.os.Bundle;
import android.os.Handler;
import android.os.HandlerThread;
import android.os.SystemClock;
import android.view.MotionEvent;
import android.view.Surface;
import android.view.SurfaceHolder;
import android.view.View;
import android.view.WindowManager;
import android.widget.Toast;

import androidx.annotation.NonNull;
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
    private static final int MAX_PHOTO_WIDTH = 1920;
    private static final int MAX_PHOTO_HEIGHT = 1440;
    private static final int MAX_VIDEO_WIDTH = 1280;
    private static final int MAX_VIDEO_HEIGHT = 720;

    private ActivityInAppCaptureBinding binding;
    private final Handler mainHandler = new Handler(android.os.Looper.getMainLooper());
    private HandlerThread cameraThread;
    private Handler cameraHandler;
    private CameraDevice cameraDevice;
    private CameraCaptureSession captureSession;
    private ImageReader imageReader;
    private MediaRecorder mediaRecorder;
    private File captureFile;
    private String cameraId = "";
    private android.util.Size previewSize;
    private android.util.Size videoSize;
    private int lensFacing = CameraCharacteristics.LENS_FACING_BACK;
    private boolean surfaceReady;
    private boolean openingCamera;
    private boolean recordingRequested;
    private boolean recordingStarting;
    private boolean recording;
    private boolean longPressTriggered;
    private boolean deliveringResult;
    private boolean captureBusy;
    private long recordingStartedAt;

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
        setContentView(binding.getRoot());
        ViewCompat.setOnApplyWindowInsetsListener(binding.getRoot(), (view, insets) -> {
            Insets bars = insets.getInsets(WindowInsetsCompat.Type.systemBars());
            binding.captureTopBar.setPadding(
                binding.captureTopBar.getPaddingLeft(), bars.top + dp(14),
                binding.captureTopBar.getPaddingRight(), binding.captureTopBar.getPaddingBottom());
            binding.captureBottomBar.setPadding(
                binding.captureBottomBar.getPaddingLeft(), binding.captureBottomBar.getPaddingTop(),
                binding.captureBottomBar.getPaddingRight(), bars.bottom + dp(28));
            return insets;
        });
        binding.captureClose.setOnClickListener(view -> cancelCapture());
        binding.captureSwitchCamera.setOnClickListener(view -> switchCamera());
        binding.captureShutter.setOnClickListener(view -> takePhoto());
        binding.captureShutter.setOnTouchListener(this::handleShutterTouch);
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
        requestCapturePermissionsIfNeeded();
    }

    @Override protected void onResume() {
        super.onResume();
        startCameraThread();
        openCameraWhenReady();
    }

    @Override protected void onPause() {
        mainHandler.removeCallbacks(beginVideoAfterHold);
        mainHandler.removeCallbacks(recordingTicker);
        if (!deliveringResult) recordingRequested = false;
        closeCamera();
        stopCameraThread();
        super.onPause();
    }

    @Override protected void onDestroy() {
        binding = null;
        super.onDestroy();
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
            binding.captureHint.setText("轻触拍照 · 长按无声录像（最多 60 秒）");
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

    private void openCameraWhenReady() {
        if (!surfaceReady || cameraDevice != null || openingCamera || !hasPermission(Manifest.permission.CAMERA)) return;
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
        if (camera == null || reader == null || surface == null || !surface.isValid() || recordingStarting) return;
        closeCaptureSession();
        try {
            CaptureRequest.Builder request = camera.createCaptureRequest(CameraDevice.TEMPLATE_PREVIEW);
            request.addTarget(surface);
            request.set(CaptureRequest.CONTROL_AF_MODE, CaptureRequest.CONTROL_AF_MODE_CONTINUOUS_PICTURE);
            camera.createCaptureSession(Arrays.asList(surface, reader.getSurface()),
                new CameraCaptureSession.StateCallback() {
                    @Override public void onConfigured(@NonNull CameraCaptureSession session) {
                        if (cameraDevice == null) { session.close(); return; }
                        captureSession = session;
                        try { session.setRepeatingRequest(request.build(), null, cameraHandler); }
                        catch (CameraAccessException ignored) { }
                        runOnUiThread(() -> setCaptureControlsEnabled(true));
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
            request.set(CaptureRequest.CONTROL_AF_MODE, CaptureRequest.CONTROL_AF_MODE_CONTINUOUS_PICTURE);
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

    private void savePhotoImage(ImageReader reader) {
        try (Image image = reader.acquireLatestImage()) {
            if (image == null) return;
            ByteBuffer buffer = image.getPlanes()[0].getBuffer();
            byte[] bytes = new byte[buffer.remaining()];
            buffer.get(bytes);
            File output = createCaptureFile(false);
            try (FileOutputStream stream = new FileOutputStream(output)) { stream.write(bytes); }
            completeCapture(output, false);
        } catch (IOException | RuntimeException exception) {
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
        captureBusy = true;
        recordingStarting = true;
        // Keep the current gesture target enabled so the held pointer can still deliver ACTION_UP.
        // A second press is already rejected by captureBusy/recordingStarting in handleShutterTouch.
        if (binding != null) {
            binding.captureShutter.setEnabled(true);
            binding.captureSwitchCamera.setEnabled(false);
            binding.captureClose.setEnabled(false);
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
                request.set(CaptureRequest.CONTROL_AF_MODE, CaptureRequest.CONTROL_AF_MODE_CONTINUOUS_VIDEO);
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
                            try {
                                session.setRepeatingRequest(request.build(), null, cameraHandler);
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
        binding.captureHint.setText("松手结束录像 · 最长 60 秒");
        binding.captureShutterCore.setBackgroundResource(R.drawable.bg_capture_shutter_recording);
        binding.captureShutter.setEnabled(true);
        binding.captureSwitchCamera.setEnabled(false);
        binding.captureClose.setEnabled(false);
        mainHandler.removeCallbacks(recordingTicker);
        mainHandler.post(recordingTicker);
        if (!recordingRequested) stopVideoRecording(false);
    }

    private void stopVideoRecording(boolean reachedLimit) {
        if (!recording) return;
        recordingRequested = false;
        recording = false;
        captureBusy = true;
        mainHandler.removeCallbacks(recordingTicker);
        long duration = Math.max(0L, SystemClock.elapsedRealtime() - recordingStartedAt);
        if (cameraHandler == null) return;
        cameraHandler.post(() -> {
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
        binding.captureHint.setText(hasPermission(Manifest.permission.RECORD_AUDIO)
            ? "轻触拍照 · 长按录像（最多 60 秒）"
            : "轻触拍照 · 长按无声录像（最多 60 秒）");
        binding.captureShutterCore.setBackgroundResource(R.drawable.bg_capture_shutter_photo);
        setCaptureControlsEnabled(true);
    }

    private void completeCapture(File file, boolean video) {
        runOnUiThread(() -> {
            if (deliveringResult || isFinishing() || isDestroyed()) return;
            deliveringResult = true;
            Uri uri = FileProvider.getUriForFile(this,
                getPackageName() + ".capture-files", file);
            Intent result = new Intent()
                .putExtra(EXTRA_CAPTURE_URI, uri)
                .putExtra(EXTRA_CAPTURE_VIDEO, video)
                .setData(uri)
                .addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION);
            setResult(RESULT_OK, result);
            finish();
        });
    }

    private void switchCamera() {
        if (recording || recordingStarting || captureBusy) return;
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
        binding.captureShutter.setEnabled(enabled);
        binding.captureSwitchCamera.setEnabled(enabled);
        binding.captureClose.setEnabled(enabled);
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
}
