package xyz.jjmxg.yiyunying.voice;

import android.content.Context;
import android.hardware.camera2.CameraAccessException;
import android.hardware.camera2.CameraCharacteristics;
import android.hardware.camera2.CameraManager;
import android.media.MediaRecorder;
import android.util.SizeF;

import com.google.gson.JsonArray;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;

import org.webrtc.AudioSource;
import org.webrtc.AudioTrack;
import org.webrtc.Camera1Enumerator;
import org.webrtc.Camera2Enumerator;
import org.webrtc.CameraEnumerationAndroid;
import org.webrtc.CameraEnumerator;
import org.webrtc.CameraVideoCapturer;
import org.webrtc.DataChannel;
import org.webrtc.DefaultVideoDecoderFactory;
import org.webrtc.DefaultVideoEncoderFactory;
import org.webrtc.EglBase;
import org.webrtc.IceCandidate;
import org.webrtc.MediaConstraints;
import org.webrtc.MediaStream;
import org.webrtc.MediaStreamTrack;
import org.webrtc.PeerConnection;
import org.webrtc.PeerConnectionFactory;
import org.webrtc.RendererCommon;
import org.webrtc.RtpReceiver;
import org.webrtc.RtpParameters;
import org.webrtc.RtpSender;
import org.webrtc.RtpTransceiver;
import org.webrtc.SdpObserver;
import org.webrtc.SessionDescription;
import org.webrtc.SurfaceTextureHelper;
import org.webrtc.SurfaceViewRenderer;
import org.webrtc.VideoCapturer;
import org.webrtc.VideoFrame;
import org.webrtc.VideoSink;
import org.webrtc.VideoSource;
import org.webrtc.VideoTrack;
import org.webrtc.audio.JavaAudioDeviceModule;

import java.util.ArrayList;
import java.util.Collections;
import java.util.List;

public final class VoiceCallEngine {
    public interface Listener {
        void onSignal(String type, JsonObject payload);
        void onConnectionState(String text, boolean connected);
        void onRemoteVideoAvailable();
        void onRemoteCameraState(boolean enabled);
        void onRemoteSystemPhoneBusy(boolean busy);
        void onLocalCameraChanged(boolean frontCamera);
        void onError(String message);
    }

    private static final Object INITIALIZE_LOCK = new Object();
    private static final double TARGET_EQUIVALENT_FOCAL_LENGTH_MM = 28d;
    private static final int TARGET_CAPTURE_WIDTH = 1920;
    private static final int TARGET_CAPTURE_HEIGHT = 1080;
    private static final int TARGET_CAPTURE_FPS = 60;
    private static final int MIN_VIDEO_BITRATE_BPS = 1_200_000;
    private static final int MAX_VIDEO_BITRATE_BPS = 6_500_000;
    private static boolean initialized;

    private final Listener listener;
    private final boolean videoEnabled;
    private final PeerConnectionFactory factory;
    private final JavaAudioDeviceModule audioDeviceModule;
    private final AudioSource audioSource;
    private final AudioTrack audioTrack;
    private final PeerConnection peerConnection;
    private final List<IceCandidate> pendingRemoteCandidates = new ArrayList<>();
    private EglBase eglBase;
    private SurfaceViewRenderer localRenderer;
    private SurfaceViewRenderer remoteRenderer;
    private SurfaceTextureHelper surfaceTextureHelper;
    private VideoCapturer videoCapturer;
    private VideoSource videoSource;
    private VideoTrack localVideoTrack;
    private RtpSender localVideoSender;
    private VideoTrack remoteVideoTrack;
    private SwitchableVideoSink localVideoSink;
    private SwitchableVideoSink remoteVideoSink;
    private AudioTrack remoteAudioTrack;
    private CameraEnumerator cameraEnumerator;
    private String frontCameraName;
    private String backCameraName;
    private String activeCameraName;
    private boolean frontCamera = true;
    private boolean remoteFrontCamera = true;
    private boolean microphoneEnabled = true;
    private boolean remoteDescriptionSet;
    private boolean closed;
    private boolean offerCreated;
    private boolean answerCreated;
    private boolean remoteFirstFrameRendered;
    private boolean localTrackInLargeSlot = true;
    private boolean compactVideoMode;
    private boolean compactVideoModeInitialized;
    private boolean localDescriptionPending;
    private boolean remoteDescriptionPending;
    private String appliedOfferSdp = "";
    private String appliedAnswerSdp = "";
    private String queuedDescriptionType = "";
    private String queuedDescriptionSdp = "";

    public VoiceCallEngine(Context context, JsonArray serverDefinitions, Listener listener) {
        this(context, serverDefinitions, listener, false, null, null);
    }

    public VoiceCallEngine(
        Context context,
        JsonArray serverDefinitions,
        Listener listener,
        boolean videoEnabled,
        SurfaceViewRenderer localRenderer,
        SurfaceViewRenderer remoteRenderer
    ) {
        this.listener = listener;
        this.videoEnabled = videoEnabled;
        initialize(context.getApplicationContext());
        audioDeviceModule = JavaAudioDeviceModule.builder(context.getApplicationContext())
            .setAudioSource(MediaRecorder.AudioSource.VOICE_COMMUNICATION)
            .setUseHardwareAcousticEchoCanceler(true)
            .setUseHardwareNoiseSuppressor(true)
            .createAudioDeviceModule();
        PeerConnectionFactory.Builder factoryBuilder = PeerConnectionFactory.builder()
            .setAudioDeviceModule(audioDeviceModule);
        if (videoEnabled) {
            if (localRenderer == null || remoteRenderer == null) {
                throw new IllegalArgumentException("视频通话缺少画面渲染控件");
            }
            eglBase = EglBase.create();
            factoryBuilder
                .setVideoEncoderFactory(new DefaultVideoEncoderFactory(eglBase.getEglBaseContext(), true, true))
                .setVideoDecoderFactory(new DefaultVideoDecoderFactory(eglBase.getEglBaseContext()));
            this.localRenderer = localRenderer;
            this.remoteRenderer = remoteRenderer;
            localVideoSink = new SwitchableVideoSink(null);
            remoteVideoSink = new SwitchableVideoSink(this::notifyRemoteVideoFrame);
            initializeRenderers();
        }
        factory = factoryBuilder.createPeerConnectionFactory();
        audioSource = factory.createAudioSource(audioConstraints());
        audioTrack = factory.createAudioTrack("yiyunying-audio", audioSource);
        audioTrack.setEnabled(true);

        PeerConnection.RTCConfiguration configuration = new PeerConnection.RTCConfiguration(parseIceServers(serverDefinitions));
        configuration.sdpSemantics = PeerConnection.SdpSemantics.UNIFIED_PLAN;
        configuration.continualGatheringPolicy = PeerConnection.ContinualGatheringPolicy.GATHER_CONTINUALLY;
        peerConnection = factory.createPeerConnection(configuration, observer());
        if (peerConnection == null) throw new IllegalStateException("无法创建 WebRTC 网络通话连接");
        addSendReceiveTrack(audioTrack);
        if (videoEnabled) initializeCamera(context.getApplicationContext());
    }

    public void createOffer() {
        if (closed || offerCreated) return;
        offerCreated = true;
        peerConnection.createOffer(new SdpAdapter() {
            @Override public void onCreateSuccess(SessionDescription description) {
                setLocalAndSend(description);
            }

            @Override public void onCreateFailure(String error) {
                listener.onError("创建通话邀请失败：" + error);
            }
        }, mediaConstraints());
    }

    public boolean restartIceAndOffer() {
        if (closed || peerConnection.signalingState() != PeerConnection.SignalingState.STABLE) return false;
        try {
            peerConnection.restartIce();
            offerCreated = false;
            createOffer();
            return true;
        } catch (RuntimeException error) {
            listener.onConnectionState("网络波动，正在重新连接", false);
            return false;
        }
    }

    public void updateIceServers(JsonArray serverDefinitions) {
        if (closed) return;
        PeerConnection.RTCConfiguration configuration =
            new PeerConnection.RTCConfiguration(parseIceServers(serverDefinitions));
        configuration.sdpSemantics = PeerConnection.SdpSemantics.UNIFIED_PLAN;
        configuration.continualGatheringPolicy =
            PeerConnection.ContinualGatheringPolicy.GATHER_CONTINUALLY;
        if (!peerConnection.setConfiguration(configuration)) {
            throw new IllegalStateException("Unable to update WebRTC ICE configuration");
        }
    }

    public void acceptSignal(String type, JsonObject payload) {
        if (closed || payload == null) return;
        if ("media".equals(type)) {
            try {
                remoteFrontCamera = !payload.has("front_camera") || payload.get("front_camera").getAsBoolean();
                refreshVideoTransforms();
                listener.onRemoteCameraState(!payload.has("camera_enabled") || payload.get("camera_enabled").getAsBoolean());
                listener.onRemoteSystemPhoneBusy(payload.has("system_phone_busy")
                    && payload.get("system_phone_busy").getAsBoolean());
            } catch (RuntimeException ignored) {
                remoteFrontCamera = true;
                refreshVideoTransforms();
                listener.onRemoteCameraState(true);
                listener.onRemoteSystemPhoneBusy(false);
            }
            return;
        }
        if ("ice".equals(type)) {
            String mid = string(payload, "sdp_mid");
            int line = integer(payload, "sdp_mline_index", 0);
            String candidateValue = string(payload, "candidate");
            if (candidateValue.isEmpty()) return;
            IceCandidate candidate = new IceCandidate(mid, line, candidateValue);
            if (remoteDescriptionSet) peerConnection.addIceCandidate(candidate);
            else pendingRemoteCandidates.add(candidate);
            return;
        }
        if (!"offer".equals(type) && !"answer".equals(type)) return;
        String sdp = string(payload, "sdp");
        if (sdp.isEmpty()) return;
        applyRemoteDescription(type, sdp);
    }

    private void applyRemoteDescription(String type, String sdp) {
        if (closed || sdp.isEmpty()) return;
        SessionDescription.Type descriptionType = "offer".equals(type)
            ? SessionDescription.Type.OFFER : SessionDescription.Type.ANSWER;
        synchronized (this) {
            if ((descriptionType == SessionDescription.Type.OFFER && sdp.equals(appliedOfferSdp))
                || (descriptionType == SessionDescription.Type.ANSWER && sdp.equals(appliedAnswerSdp))) return;
            if (remoteDescriptionPending) {
                queuedDescriptionType = type;
                queuedDescriptionSdp = sdp;
                return;
            }
            PeerConnection.SignalingState signalingState = peerConnection.signalingState();
            if (descriptionType == SessionDescription.Type.ANSWER) {
                if (signalingState == PeerConnection.SignalingState.STABLE) {
                    if (localDescriptionPending) {
                        queuedDescriptionType = type;
                        queuedDescriptionSdp = sdp;
                    } else {
                        appliedAnswerSdp = sdp;
                    }
                    return;
                }
                if (signalingState != PeerConnection.SignalingState.HAVE_LOCAL_OFFER) {
                    queuedDescriptionType = type;
                    queuedDescriptionSdp = sdp;
                    return;
                }
            } else if (signalingState != PeerConnection.SignalingState.STABLE) {
                if (signalingState == PeerConnection.SignalingState.HAVE_REMOTE_OFFER) appliedOfferSdp = sdp;
                else {
                    queuedDescriptionType = type;
                    queuedDescriptionSdp = sdp;
                }
                return;
            }
            if (descriptionType == SessionDescription.Type.OFFER) {
                answerCreated = false;
                // During renegotiation, ICE rows can arrive while setRemoteDescription is
                // still asynchronous. Queue them against the new offer, not the old ufrag.
                remoteDescriptionSet = false;
            }
            remoteDescriptionPending = true;
            if (descriptionType == SessionDescription.Type.OFFER) appliedOfferSdp = sdp;
            else appliedAnswerSdp = sdp;
        }
        peerConnection.setRemoteDescription(new SdpAdapter() {
            @Override public void onSetSuccess() {
                synchronized (VoiceCallEngine.this) {
                    remoteDescriptionPending = false;
                    remoteDescriptionSet = true;
                }
                flushCandidates();
                attachAllRemoteReceivers();
                if (descriptionType == SessionDescription.Type.OFFER) createAnswer();
                processQueuedDescription();
            }

            @Override public void onSetFailure(String error) {
                synchronized (VoiceCallEngine.this) {
                    remoteDescriptionPending = false;
                    if (descriptionType == SessionDescription.Type.OFFER) appliedOfferSdp = "";
                    else appliedAnswerSdp = "";
                }
                if (error == null || !error.contains("wrong state")) {
                    listener.onError("读取对方通话信息失败，请重新连接");
                }
                processQueuedDescription();
            }
        }, new SessionDescription(descriptionType, sdp));
    }

    private void processQueuedDescription() {
        String type;
        String sdp;
        synchronized (this) {
            if (closed || remoteDescriptionPending || queuedDescriptionSdp.isEmpty()) return;
            type = queuedDescriptionType;
            sdp = queuedDescriptionSdp;
            queuedDescriptionType = "";
            queuedDescriptionSdp = "";
        }
        applyRemoteDescription(type, sdp);
    }

    public void setMicrophoneEnabled(boolean enabled) {
        microphoneEnabled = enabled;
        if (closed) return;
        audioTrack.setEnabled(enabled);
    }

    public void ensureAudioActive() {
        if (closed) return;
        audioTrack.setEnabled(microphoneEnabled);
        attachAllRemoteReceivers();
        if (remoteAudioTrack != null) remoteAudioTrack.setEnabled(true);
    }

    public void setCameraEnabled(boolean enabled) {
        if (!closed && localVideoTrack != null) localVideoTrack.setEnabled(enabled);
    }

    public void switchCamera() {
        if (closed || !(videoCapturer instanceof CameraVideoCapturer)) {
            listener.onError("当前设备无法切换摄像头");
            return;
        }
        String target = frontCamera ? backCameraName : frontCameraName;
        if (target == null || target.isEmpty() || target.equals(activeCameraName)) {
            listener.onError("当前设备没有可切换的另一枚标准摄像头");
            return;
        }
        String selectedTarget = target;
        ((CameraVideoCapturer) videoCapturer).switchCamera(new CameraVideoCapturer.CameraSwitchHandler() {
            @Override public void onCameraSwitchDone(boolean isFrontCamera) {
                frontCamera = isFrontCamera;
                activeCameraName = selectedTarget;
                refreshVideoTransforms();
                listener.onLocalCameraChanged(frontCamera);
            }

            @Override public void onCameraSwitchError(String errorDescription) {
                listener.onError("切换摄像头失败：" + errorDescription);
            }
        }, selectedTarget);
    }

    public void close() {
        if (closed) return;
        closed = true;
        if (remoteVideoTrack != null && remoteVideoSink != null) {
            try { remoteVideoTrack.removeSink(remoteVideoSink); } catch (RuntimeException ignored) { }
        }
        if (localVideoTrack != null && localVideoSink != null) {
            try { localVideoTrack.removeSink(localVideoSink); } catch (RuntimeException ignored) { }
        }
        if (localVideoSink != null) localVideoSink.setTarget(null);
        if (remoteVideoSink != null) remoteVideoSink.setTarget(null);
        if (videoCapturer != null) {
            try { videoCapturer.stopCapture(); } catch (InterruptedException ignored) { Thread.currentThread().interrupt(); }
            catch (RuntimeException ignored) { }
            try { videoCapturer.dispose(); } catch (RuntimeException ignored) { }
        }
        try { peerConnection.close(); } catch (RuntimeException ignored) { }
        try { peerConnection.dispose(); } catch (RuntimeException ignored) { }
        if (localVideoTrack != null) try { localVideoTrack.dispose(); } catch (RuntimeException ignored) { }
        if (videoSource != null) try { videoSource.dispose(); } catch (RuntimeException ignored) { }
        if (surfaceTextureHelper != null) try { surfaceTextureHelper.dispose(); } catch (RuntimeException ignored) { }
        try { audioTrack.dispose(); } catch (RuntimeException ignored) { }
        try { audioSource.dispose(); } catch (RuntimeException ignored) { }
        try { factory.dispose(); } catch (RuntimeException ignored) { }
        try { audioDeviceModule.release(); } catch (RuntimeException ignored) { }
        if (localRenderer != null) try { localRenderer.release(); } catch (RuntimeException ignored) { }
        if (remoteRenderer != null) try { remoteRenderer.release(); } catch (RuntimeException ignored) { }
        if (eglBase != null) try { eglBase.release(); } catch (RuntimeException ignored) { }
        pendingRemoteCandidates.clear();
    }

    private void initializeRenderers() {
        localRenderer.init(eglBase.getEglBaseContext(), null);
        localRenderer.setEnableHardwareScaler(true);
        localRenderer.setScalingType(RendererCommon.ScalingType.SCALE_ASPECT_FIT);
        localRenderer.setZOrderMediaOverlay(false);
        localRenderer.setMirror(true);
        remoteRenderer.init(eglBase.getEglBaseContext(), null);
        remoteRenderer.setEnableHardwareScaler(true);
        remoteRenderer.setScalingType(RendererCommon.ScalingType.SCALE_ASPECT_FILL);
        remoteRenderer.setZOrderMediaOverlay(true);
        routeVideoSinks();
        refreshVideoTransforms();
    }

    public void setLocalVideoLarge(boolean localLarge) {
        if (closed || localRenderer == null || remoteRenderer == null) return;
        if (localTrackInLargeSlot == localLarge) return;
        localTrackInLargeSlot = localLarge;
        routeVideoSinks();
        refreshVideoTransforms();
    }

    /** Keep the system picture-in-picture window filled without ever stretching video frames. */
    public void setCompactVideoMode(boolean compact) {
        if (closed || localRenderer == null || remoteRenderer == null) return;
        if (compactVideoModeInitialized && compactVideoMode == compact) return;
        compactVideoMode = compact;
        compactVideoModeInitialized = true;
        localRenderer.setScalingType(compact
            ? RendererCommon.ScalingType.SCALE_ASPECT_FILL
            : RendererCommon.ScalingType.SCALE_ASPECT_FIT);
        remoteRenderer.setScalingType(RendererCommon.ScalingType.SCALE_ASPECT_FILL);
    }

    private void routeVideoSinks() {
        if (localVideoSink == null || remoteVideoSink == null) return;
        localVideoSink.setTarget(localTrackInLargeSlot ? localRenderer : remoteRenderer);
        remoteVideoSink.setTarget(localTrackInLargeSlot ? remoteRenderer : localRenderer);
    }

    private void notifyRemoteVideoFrame() {
        synchronized (this) {
            if (closed || remoteFirstFrameRendered) return;
            remoteFirstFrameRendered = true;
        }
        listener.onRemoteVideoAvailable();
    }

    public void refreshVideoTransforms() {
        if (closed) return;
        applyVideoTransforms();
        if (localRenderer != null) localRenderer.post(this::applyVideoTransforms);
        if (remoteRenderer != null) remoteRenderer.postDelayed(this::applyVideoTransforms, 32L);
    }

    private void applyVideoTransforms() {
        if (closed) return;
        // Mirroring follows the track routed into each fixed render slot.
        if (localRenderer != null) {
            localRenderer.setMirror(localTrackInLargeSlot ? frontCamera : remoteFrontCamera);
        }
        if (remoteRenderer != null) {
            remoteRenderer.setMirror(localTrackInLargeSlot ? remoteFrontCamera : frontCamera);
        }
    }

    public boolean isFrontCamera() {
        return frontCamera;
    }

    private void initializeCamera(Context context) {
        cameraEnumerator = Camera2Enumerator.isSupported(context)
            ? new Camera2Enumerator(context) : new Camera1Enumerator(true);
        frontCameraName = selectCamera(context, cameraEnumerator, true);
        backCameraName = selectCamera(context, cameraEnumerator, false);
        String selected = frontCameraName;
        if (selected == null) selected = backCameraName;
        if (selected == null) throw new IllegalStateException("当前设备没有可用摄像头");
        activeCameraName = selected;
        frontCamera = cameraEnumerator.isFrontFacing(selected);
        videoCapturer = cameraEnumerator.createCapturer(selected, null);
        if (videoCapturer == null) throw new IllegalStateException("无法打开摄像头");
        videoSource = factory.createVideoSource(false);
        surfaceTextureHelper = SurfaceTextureHelper.create("YiyunyingVideoCapture", eglBase.getEglBaseContext());
        videoCapturer.initialize(surfaceTextureHelper, context, videoSource.getCapturerObserver());
        CaptureSettings capture = selectCaptureSettings(cameraEnumerator, selected);
        videoSource.adaptOutputFormat(capture.width, capture.height, capture.fps);
        videoCapturer.startCapture(capture.width, capture.height, capture.fps);
        localVideoTrack = factory.createVideoTrack("yiyunying-video", videoSource);
        localVideoTrack.setEnabled(true);
        localVideoTrack.addSink(localVideoSink);
        routeVideoSinks();
        refreshVideoTransforms();
        addSendReceiveTrack(localVideoTrack);
    }

    private static String selectCamera(Context context, CameraEnumerator enumerator, boolean front) {
        String fallback = null;
        String selected = null;
        double selectedScore = Double.POSITIVE_INFINITY;
        for (String name : enumerator.getDeviceNames()) {
            if (front != enumerator.isFrontFacing(name)) continue;
            if (fallback == null) fallback = name;
            double score = cameraNormalLensScore(context, name);
            if (score < selectedScore) {
                selected = name;
                selectedScore = score;
            }
        }
        return selected == null ? fallback : selected;
    }

    private static double cameraNormalLensScore(Context context, String cameraName) {
        try {
            CameraManager manager = (CameraManager) context.getSystemService(Context.CAMERA_SERVICE);
            if (manager == null) return Double.POSITIVE_INFINITY;
            CameraCharacteristics characteristics = manager.getCameraCharacteristics(cameraName);
            SizeF sensor = characteristics.get(CameraCharacteristics.SENSOR_INFO_PHYSICAL_SIZE);
            float[] focalLengths = characteristics.get(CameraCharacteristics.LENS_INFO_AVAILABLE_FOCAL_LENGTHS);
            if (sensor == null || focalLengths == null || focalLengths.length == 0) {
                return Double.POSITIVE_INFINITY;
            }
            double best = Double.POSITIVE_INFINITY;
            for (float focalLength : focalLengths) {
                best = Math.min(best, normalLensScore(sensor.getWidth(), focalLength));
            }
            return best;
        } catch (CameraAccessException | RuntimeException ignored) {
            return Double.POSITIVE_INFINITY;
        }
    }

    static double normalLensScore(float sensorWidthMillimeters, float focalLengthMillimeters) {
        if (sensorWidthMillimeters <= 0f || focalLengthMillimeters <= 0f) {
            return Double.POSITIVE_INFINITY;
        }
        return Math.abs(equivalentFocalLengthMm(sensorWidthMillimeters, focalLengthMillimeters)
            - TARGET_EQUIVALENT_FOCAL_LENGTH_MM);
    }

    static double equivalentFocalLengthMm(float sensorWidthMillimeters, float focalLengthMillimeters) {
        if (sensorWidthMillimeters <= 0f || focalLengthMillimeters <= 0f) {
            return Double.POSITIVE_INFINITY;
        }
        return focalLengthMillimeters * 36d / sensorWidthMillimeters;
    }

    static double captureFormatScore(int width, int height, int maximumFps) {
        if (width <= 0 || height <= 0 || maximumFps <= 0) return Double.POSITIVE_INFINITY;
        int landscapeWidth = Math.max(width, height);
        int landscapeHeight = Math.min(width, height);
        double pixels = (double) landscapeWidth * landscapeHeight;
        double targetPixels = (double) TARGET_CAPTURE_WIDTH * TARGET_CAPTURE_HEIGHT;
        double resolutionPenalty = Math.abs(Math.log(pixels / targetPixels)) * 100d;
        if (pixels > targetPixels) {
            resolutionPenalty += (pixels / targetPixels - 1d) * 250d;
        }
        double aspectPenalty = Math.abs((double) landscapeWidth / landscapeHeight - 16d / 9d) * 120d;
        double frameRatePenalty = maximumFps < TARGET_CAPTURE_FPS
            ? (TARGET_CAPTURE_FPS - maximumFps) * 20d
            : (maximumFps - TARGET_CAPTURE_FPS) * 0.2d;
        return resolutionPenalty + aspectPenalty + frameRatePenalty;
    }

    private static CaptureSettings selectCaptureSettings(CameraEnumerator enumerator, String cameraName) {
        CaptureSettings selected = null;
        double selectedScore = Double.POSITIVE_INFINITY;
        try {
            List<CameraEnumerationAndroid.CaptureFormat> formats = enumerator.getSupportedFormats(cameraName);
            if (formats != null) {
                for (CameraEnumerationAndroid.CaptureFormat format : formats) {
                    if (format == null || format.framerate == null) continue;
                    int maximumFps = normalizeCameraFps(format.framerate.max);
                    double score = captureFormatScore(format.width, format.height, maximumFps);
                    if (score < selectedScore) {
                        selected = new CaptureSettings(format.width, format.height,
                            Math.max(15, Math.min(TARGET_CAPTURE_FPS, maximumFps)));
                        selectedScore = score;
                    }
                }
            }
        } catch (RuntimeException ignored) {
            selected = null;
        }
        return selected == null
            ? new CaptureSettings(1280, 720, 30)
            : selected;
    }

    private static int normalizeCameraFps(int value) {
        return value > 1000 ? Math.max(1, Math.round(value / 1000f)) : value;
    }

    private void createAnswer() {
        synchronized (this) {
            if (closed || answerCreated) return;
            answerCreated = true;
        }
        peerConnection.createAnswer(new SdpAdapter() {
            @Override public void onCreateSuccess(SessionDescription description) {
                setLocalAndSend(description);
            }

            @Override public void onCreateFailure(String error) {
                synchronized (VoiceCallEngine.this) { answerCreated = false; }
                listener.onError("创建通话应答失败：" + error);
            }
        }, mediaConstraints());
    }

    private void setLocalAndSend(SessionDescription description) {
        synchronized (this) { localDescriptionPending = true; }
        peerConnection.setLocalDescription(new SdpAdapter() {
            @Override public void onSetSuccess() {
                synchronized (VoiceCallEngine.this) { localDescriptionPending = false; }
                JsonObject payload = new JsonObject();
                payload.addProperty("sdp", description.description);
                listener.onSignal(description.type.canonicalForm(), payload);
                processQueuedDescription();
            }

            @Override public void onSetFailure(String error) {
                synchronized (VoiceCallEngine.this) { localDescriptionPending = false; }
                listener.onError("保存本机通话信息失败：" + error);
                processQueuedDescription();
            }
        }, description);
    }

    private PeerConnection.Observer observer() {
        return new PeerConnection.Observer() {
            @Override public void onSignalingChange(PeerConnection.SignalingState state) { }

            @Override public void onIceConnectionChange(PeerConnection.IceConnectionState state) {
                if (state == PeerConnection.IceConnectionState.CONNECTED
                    || state == PeerConnection.IceConnectionState.COMPLETED) {
                    ensureAudioActive();
                    listener.onConnectionState(videoEnabled ? "视频已连接" : "语音已连接", true);
                } else if (state == PeerConnection.IceConnectionState.DISCONNECTED) {
                    listener.onConnectionState("网络波动，正在重新连接", false);
                } else if (state == PeerConnection.IceConnectionState.FAILED) {
                    listener.onConnectionState("网络通话连接失败，正在重新连接", false);
                }
            }

            @Override public void onStandardizedIceConnectionChange(PeerConnection.IceConnectionState state) { }

            @Override public void onConnectionChange(PeerConnection.PeerConnectionState state) {
                if (state == PeerConnection.PeerConnectionState.CONNECTED) {
                    ensureAudioActive();
                    listener.onConnectionState(videoEnabled ? "视频已连接" : "语音已连接", true);
                } else if (state == PeerConnection.PeerConnectionState.FAILED) {
                    listener.onConnectionState("网络通话连接失败，正在重新连接", false);
                }
            }

            @Override public void onIceConnectionReceivingChange(boolean receiving) { }
            @Override public void onIceGatheringChange(PeerConnection.IceGatheringState state) { }

            @Override public void onIceCandidate(IceCandidate candidate) {
                JsonObject payload = new JsonObject();
                payload.addProperty("sdp_mid", candidate.sdpMid == null ? "" : candidate.sdpMid);
                payload.addProperty("sdp_mline_index", candidate.sdpMLineIndex);
                payload.addProperty("candidate", candidate.sdp);
                listener.onSignal("ice", payload);
            }

            @Override public void onIceCandidatesRemoved(IceCandidate[] candidates) { }

            @Override public void onAddStream(MediaStream stream) {
                if (stream == null) return;
                if (!stream.audioTracks.isEmpty()) attachRemoteAudio(stream.audioTracks.get(0));
                if (videoEnabled && !stream.videoTracks.isEmpty()) attachRemoteVideo(stream.videoTracks.get(0));
            }

            @Override public void onRemoveStream(MediaStream stream) { }
            @Override public void onDataChannel(DataChannel channel) { }
            @Override public void onRenegotiationNeeded() { }

            @Override public void onAddTrack(RtpReceiver receiver, MediaStream[] streams) {
                attachRemoteReceiver(receiver);
            }

            @Override public void onTrack(RtpTransceiver transceiver) {
                if (transceiver != null) attachRemoteReceiver(transceiver.getReceiver());
            }
        };
    }

    private void attachRemoteReceiver(RtpReceiver receiver) {
        if (receiver == null) return;
        MediaStreamTrack track = receiver.track();
        if (track instanceof AudioTrack) {
            attachRemoteAudio((AudioTrack) track);
            return;
        }
        if (!videoEnabled) return;
        if (track instanceof VideoTrack) attachRemoteVideo((VideoTrack) track);
    }

    private void attachRemoteAudio(AudioTrack track) {
        if (closed || track == null) return;
        remoteAudioTrack = track;
        remoteAudioTrack.setEnabled(true);
    }

    private void attachAllRemoteReceivers() {
        if (closed) return;
        for (RtpReceiver receiver : peerConnection.getReceivers()) attachRemoteReceiver(receiver);
    }

    private void attachRemoteVideo(VideoTrack track) {
        if (closed || track == null || remoteVideoSink == null) return;
        if (remoteVideoTrack == track) {
            remoteVideoTrack.setEnabled(true);
            refreshVideoTransforms();
            return;
        }
        if (remoteVideoTrack != null && remoteVideoTrack != track) {
            try { remoteVideoTrack.removeSink(remoteVideoSink); } catch (RuntimeException ignored) { }
        }
        remoteFirstFrameRendered = false;
        remoteVideoSink.resetFirstFrame();
        remoteVideoTrack = track;
        remoteVideoTrack.setEnabled(true);
        remoteVideoTrack.addSink(remoteVideoSink);
        routeVideoSinks();
        refreshVideoTransforms();
    }

    private static final class SwitchableVideoSink implements VideoSink {
        private final Runnable firstFrameAction;
        private volatile VideoSink target;
        private boolean firstFrameDelivered;

        SwitchableVideoSink(Runnable firstFrameAction) {
            this.firstFrameAction = firstFrameAction;
        }

        void setTarget(VideoSink target) {
            this.target = target;
        }

        synchronized void resetFirstFrame() {
            firstFrameDelivered = false;
        }

        @Override public void onFrame(VideoFrame frame) {
            VideoSink currentTarget = target;
            if (currentTarget != null) currentTarget.onFrame(frame);
            if (firstFrameAction == null) return;
            synchronized (this) {
                if (firstFrameDelivered) return;
                firstFrameDelivered = true;
            }
            firstFrameAction.run();
        }
    }

    private void flushCandidates() {
        for (IceCandidate candidate : pendingRemoteCandidates) peerConnection.addIceCandidate(candidate);
        pendingRemoteCandidates.clear();
    }

    private MediaConstraints mediaConstraints() {
        MediaConstraints constraints = new MediaConstraints();
        constraints.mandatory.add(new MediaConstraints.KeyValuePair("OfferToReceiveAudio", "true"));
        constraints.mandatory.add(new MediaConstraints.KeyValuePair("OfferToReceiveVideo", videoEnabled ? "true" : "false"));
        return constraints;
    }

    private static RtpTransceiver.RtpTransceiverInit sendReceiveTransceiver() {
        return new RtpTransceiver.RtpTransceiverInit(
            RtpTransceiver.RtpTransceiverDirection.SEND_RECV,
            Collections.singletonList("yiyunying-stream"));
    }

    private void addSendReceiveTrack(MediaStreamTrack track) {
        RtpSender sender = peerConnection.addTrack(track, Collections.singletonList("yiyunying-stream"));
        if (sender == null) {
            RtpTransceiver transceiver = peerConnection.addTransceiver(track, sendReceiveTransceiver());
            if (track == localVideoTrack && transceiver != null) {
                localVideoSender = transceiver.getSender();
                configureVideoSender(localVideoSender);
            }
            return;
        }
        if (track == localVideoTrack) {
            localVideoSender = sender;
            configureVideoSender(localVideoSender);
        }
        for (RtpTransceiver transceiver : peerConnection.getTransceivers()) {
            if (transceiver != null && isSenderForTrack(transceiver.getSender(), sender, track)) {
                transceiver.setDirection(RtpTransceiver.RtpTransceiverDirection.SEND_RECV);
                return;
            }
        }
    }

    private static void configureVideoSender(RtpSender sender) {
        if (sender == null) return;
        try {
            RtpParameters parameters = sender.getParameters();
            if (parameters == null) return;
            parameters.degradationPreference = RtpParameters.DegradationPreference.MAINTAIN_FRAMERATE;
            if (parameters.encodings != null) {
                for (RtpParameters.Encoding encoding : parameters.encodings) {
                    if (encoding == null) continue;
                    encoding.minBitrateBps = MIN_VIDEO_BITRATE_BPS;
                    encoding.maxBitrateBps = MAX_VIDEO_BITRATE_BPS;
                    encoding.maxFramerate = TARGET_CAPTURE_FPS;
                    encoding.scaleResolutionDownBy = 1d;
                    encoding.bitratePriority = 1.5d;
                }
            }
            sender.setParameters(parameters);
        } catch (RuntimeException ignored) {
            // Some vendor WebRTC builds reject one or more optional encoding hints.
        }
    }

    private static boolean isSenderForTrack(RtpSender candidate, RtpSender expected, MediaStreamTrack track) {
        if (candidate == null) return false;
        if (candidate == expected) return true;
        MediaStreamTrack candidateTrack = candidate.track();
        return candidateTrack != null && track != null && candidateTrack.id().equals(track.id());
    }

    private static List<PeerConnection.IceServer> parseIceServers(JsonArray definitions) {
        List<PeerConnection.IceServer> directServers = new ArrayList<>();
        List<PeerConnection.IceServer> relayServers = new ArrayList<>();
        if (definitions != null) {
            for (JsonElement element : definitions) {
                if (!element.isJsonObject()) continue;
                JsonObject object = element.getAsJsonObject();
                List<String> directUrls = new ArrayList<>();
                List<String> relayUrls = new ArrayList<>();
                if (object.has("urls") && object.get("urls").isJsonArray()) {
                    for (JsonElement value : object.getAsJsonArray("urls")) {
                        if (value.isJsonPrimitive() && !value.getAsString().trim().isEmpty()) {
                            addIceUrl(value.getAsString().trim(), directUrls, relayUrls);
                        }
                    }
                } else {
                    String single = string(object, "urls");
                    if (!single.isEmpty()) addIceUrl(single, directUrls, relayUrls);
                }
                String username = string(object, "username");
                String credential = string(object, "credential");
                addIceServer(directServers, directUrls, username, credential);
                addIceServer(relayServers, relayUrls, username, credential);
            }
        }
        if (directServers.isEmpty() && relayServers.isEmpty()) {
            directServers.add(PeerConnection.IceServer.builder("stun:stun.l.google.com:19302").createIceServer());
        }
        List<PeerConnection.IceServer> servers = new ArrayList<>(directServers.size() + relayServers.size());
        servers.addAll(directServers);
        servers.addAll(relayServers);
        return servers;
    }

    private static void addIceUrl(String value, List<String> directUrls, List<String> relayUrls) {
        String normalized = value == null ? "" : value.trim();
        if (normalized.isEmpty()) return;
        String lower = normalized.toLowerCase(java.util.Locale.ROOT);
        if (lower.startsWith("turn:") || lower.startsWith("turns:")) relayUrls.add(normalized);
        else directUrls.add(normalized);
    }

    private static void addIceServer(
        List<PeerConnection.IceServer> target,
        List<String> urls,
        String username,
        String credential
    ) {
        if (urls.isEmpty()) return;
        PeerConnection.IceServer.Builder builder = PeerConnection.IceServer.builder(urls);
        if (!username.isEmpty()) builder.setUsername(username);
        if (!credential.isEmpty()) builder.setPassword(credential);
        target.add(builder.createIceServer());
    }

    private static MediaConstraints audioConstraints() {
        MediaConstraints constraints = new MediaConstraints();
        constraints.optional.add(new MediaConstraints.KeyValuePair("googEchoCancellation", "true"));
        constraints.optional.add(new MediaConstraints.KeyValuePair("googAutoGainControl", "true"));
        constraints.optional.add(new MediaConstraints.KeyValuePair("googNoiseSuppression", "true"));
        constraints.optional.add(new MediaConstraints.KeyValuePair("googHighpassFilter", "true"));
        return constraints;
    }

    private static void initialize(Context context) {
        synchronized (INITIALIZE_LOCK) {
            if (initialized) return;
            PeerConnectionFactory.initialize(PeerConnectionFactory.InitializationOptions.builder(context)
                .setEnableInternalTracer(false)
                .createInitializationOptions());
            initialized = true;
        }
    }

    private static String string(JsonObject object, String key) {
        try {
            return object != null && object.has(key) && !object.get(key).isJsonNull()
                ? object.get(key).getAsString() : "";
        } catch (RuntimeException ignored) {
            return "";
        }
    }

    private static int integer(JsonObject object, String key, int fallback) {
        try {
            return object != null && object.has(key) ? object.get(key).getAsInt() : fallback;
        } catch (RuntimeException ignored) {
            return fallback;
        }
    }

    private static class SdpAdapter implements SdpObserver {
        @Override public void onCreateSuccess(SessionDescription description) { }
        @Override public void onSetSuccess() { }
        @Override public void onCreateFailure(String error) { }
        @Override public void onSetFailure(String error) { }
    }

    private static final class CaptureSettings {
        final int width;
        final int height;
        final int fps;

        CaptureSettings(int width, int height, int fps) {
            this.width = width;
            this.height = height;
            this.fps = fps;
        }
    }
}
