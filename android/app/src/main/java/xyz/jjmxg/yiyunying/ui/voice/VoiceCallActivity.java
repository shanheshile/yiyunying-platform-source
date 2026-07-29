package xyz.jjmxg.yiyunying.ui.voice;

import android.Manifest;
import android.app.PendingIntent;
import android.app.PictureInPictureParams;
import android.app.RemoteAction;
import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.content.IntentFilter;
import android.content.pm.PackageManager;
import android.content.res.Configuration;
import android.graphics.Rect;
import android.graphics.drawable.Icon;
import android.media.AudioAttributes;
import android.media.AudioDeviceInfo;
import android.media.AudioFocusRequest;
import android.media.AudioManager;
import android.os.Build;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.os.SystemClock;
import android.telephony.PhoneStateListener;
import android.telephony.TelephonyCallback;
import android.telephony.TelephonyManager;
import android.util.Rational;
import android.view.Gravity;
import android.view.HapticFeedbackConstants;
import android.view.MotionEvent;
import android.view.View;
import android.view.ViewConfiguration;
import android.view.WindowManager;
import android.view.animation.AccelerateDecelerateInterpolator;
import android.view.animation.DecelerateInterpolator;

import androidx.activity.OnBackPressedCallback;
import androidx.activity.result.ActivityResultLauncher;
import androidx.activity.result.contract.ActivityResultContracts;
import androidx.annotation.NonNull;
import androidx.annotation.RequiresApi;
import androidx.core.content.ContextCompat;

import com.google.android.material.button.MaterialButton;
import com.google.android.material.snackbar.Snackbar;
import com.google.gson.JsonArray;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;

import java.util.ArrayDeque;
import java.util.ArrayList;
import java.util.Collections;
import java.util.LinkedHashMap;
import java.util.List;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.core.AppAccess;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.data.api.RequestHandle;
import xyz.jjmxg.yiyunying.databinding.ActivityVoiceCallBinding;
import xyz.jjmxg.yiyunying.domain.call.CallDurationClock;
import xyz.jjmxg.yiyunying.service.MessageNotificationService;
import xyz.jjmxg.yiyunying.service.VoiceCallForegroundService;
import xyz.jjmxg.yiyunying.ui.common.ImageLoader;
import xyz.jjmxg.yiyunying.ui.common.SystemInsetActivity;
import xyz.jjmxg.yiyunying.voice.VoiceCallEngine;

public final class VoiceCallActivity extends SystemInsetActivity implements VoiceCallEngine.Listener {
    private static final String EXTRA_CALL_ID = "call_id";
    private static final String EXTRA_PEER_ID = "peer_id";
    private static final String EXTRA_PEER_NAME = "peer_name";
    private static final String EXTRA_PEER_AVATAR = "peer_avatar";
    private static final String EXTRA_INCOMING = "incoming";
    private static final String EXTRA_CALL_TYPE = "call_type";
    private static final String EXTRA_CONTEXT_TYPE = "context_type";
    private static final String EXTRA_CONTEXT_ID = "context_id";
    private static final String EXTRA_CONTEXT_NAME = "context_name";

    private ActivityVoiceCallBinding binding;
    private final Handler handler = new Handler(Looper.getMainLooper());
    private RequestHandle createRequest;
    private RequestHandle actionRequest;
    private RequestHandle stateRequest;
    private RequestHandle signalRequest;
    private RequestHandle signalSendRequest;
    private final ArrayDeque<JsonObject> pendingSignals = new ArrayDeque<>();
    private VoiceCallEngine engine;
    private AudioManager audioManager;
    private int previousAudioMode = AudioManager.MODE_NORMAL;
    private boolean previousSpeaker;
    private boolean previousMicrophoneMute;
    private AudioFocusRequest audioFocusRequest;
    private boolean audioFocusGranted;
    private long callId;
    private long peerId;
    private String peerName = "好友";
    private String peerAvatar = "";
    private String direction = "";
    private String status = "";
    private String callType = "audio";
    private String contextType = "private";
    private long contextId;
    private String contextName = "";
    private long lastSignalId;
    private long signalPollMs = 100L;
    private long statePollMs = 350L;
    private final CallDurationClock durationClock = new CallDurationClock();
    private boolean microphoneEnabled = true;
    private boolean speakerEnabled;
    private boolean cameraEnabled = true;
    private boolean remoteCameraEnabled = true;
    private boolean remoteSystemPhoneBusy;
    private boolean remoteVideoAvailable;
    private String renderedAvatarKey;
    private String lastVideoRenderState = "";
    private boolean introCollapsed;
    private boolean callConnected;
    private boolean mediaEverConnected;
    private boolean callControlsHidden;
    private boolean localVideoLarge = true;
    private boolean videoFocusAnimating;
    private boolean videoPreviewDragging;
    private int videoPreviewTouchSlop;
    private float videoPreviewDownX;
    private float videoPreviewDownY;
    private float videoPreviewStartX;
    private float videoPreviewStartY;
    private boolean permissionForAnswer;
    private boolean previewEngine;
    private boolean foregroundStarted;
    private boolean terminal;
    private boolean endingLocally;
    private boolean resumeOffer;
    private boolean localOfferOwner;
    private boolean hangupDeliveryPending;
    private boolean registeredReceiver;
    private boolean systemPhoneBusy;
    private boolean autoMutedForSystemCall;
    private int stateReadFailures;
    private int reconnectAttempts;
    private TelephonyManager telephonyManager;
    private PhoneStateListener legacyPhoneStateListener;
    private TelephonyCallback phoneStateCallback;
    private JsonArray iceServers = new JsonArray();

    private final Runnable statePoller = this::loadState;
    private final Runnable signalPoller = this::loadSignals;
    private final Runnable signalSender = this::flushPendingSignals;
    private final Runnable reconnectPeer = new Runnable() {
        @Override public void run() {
            if (binding == null || terminal || callConnected || engine == null || !localOfferOwner) return;
            if (reconnectAttempts >= 5) {
                binding.status.setText("连接仍在恢复，可再次点击通话重新接入");
                return;
            }
            reconnectAttempts++;
            boolean restarted = engine.restartIceAndOffer();
            long delay = restarted ? 1800L : Math.min(3500L, 500L * reconnectAttempts);
            handler.postDelayed(this, delay);
        }
    };
    private final Runnable collapseIntro = () -> {
        if (binding == null || isInPictureInPictureMode()) return;
        introCollapsed = true;
        binding.callTypeTitle.setVisibility(View.GONE);
        binding.networkHint.setVisibility(View.GONE);
        binding.backButton.setVisibility(View.VISIBLE);
    };
    private final Runnable closeAfterCall = () -> {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.LOLLIPOP) finishAndRemoveTask();
        else finish();
    };
    private final Runnable durationTicker = new Runnable() {
        @Override public void run() {
            if (binding == null) return;
            long seconds = durationClock.seconds(SystemClock.elapsedRealtime());
            String duration = String.format(java.util.Locale.CHINA, "%02d:%02d", seconds / 60L, seconds % 60L);
            binding.duration.setText(duration);
            binding.pipDuration.setText(duration);
            handler.postDelayed(this, 1000L);
        }
    };

    private final ActivityResultLauncher<String[]> callPermissions = registerForActivityResult(
        new ActivityResultContracts.RequestMultiplePermissions(), granted -> {
            registerSystemCallMonitor();
            if (!hasRequiredPermissions()) {
                message(isVideoCall()
                    ? "需要麦克风和摄像头权限才能进行应用内网络视频通话"
                    : "需要麦克风权限才能进行应用内网络语音通话");
                if (callId <= 0) finishCall("未能发起通话", true);
                return;
            }
            if (permissionForAnswer) {
                permissionForAnswer = false;
                answer();
            } else if (callId <= 0) {
                startOutgoingCall();
            } else {
                initializeEngine();
            }
        });

    private final BroadcastReceiver endedReceiver = new BroadcastReceiver() {
        @Override public void onReceive(Context context, Intent intent) {
            long endedCall = intent == null ? 0 : intent.getLongExtra(VoiceCallForegroundService.EXTRA_CALL_ID, 0);
            if (endedCall == callId) {
                hangupDeliveryPending = false;
                finishCall("通话已结束", true);
            }
        }
    };

    public static void startOutgoing(Context context, long peerId, String peerName, String avatar, boolean video) {
        startOutgoing(context, peerId, peerName, avatar, video, "private", 0, "");
    }

    public static void startOutgoing(Context context, long peerId, String peerName, String avatar, boolean video,
                                     String contextType, long contextId, String contextName) {
        Intent intent = new Intent(context, VoiceCallActivity.class)
            .putExtra(EXTRA_PEER_ID, peerId)
            .putExtra(EXTRA_PEER_NAME, peerName)
            .putExtra(EXTRA_PEER_AVATAR, avatar == null ? "" : avatar)
            .putExtra(EXTRA_CALL_TYPE, video ? "video" : "audio")
            .putExtra(EXTRA_CONTEXT_TYPE, contextType == null ? "private" : contextType)
            .putExtra(EXTRA_CONTEXT_ID, contextId)
            .putExtra(EXTRA_CONTEXT_NAME, contextName == null ? "" : contextName)
            .addFlags(Intent.FLAG_ACTIVITY_SINGLE_TOP | Intent.FLAG_ACTIVITY_REORDER_TO_FRONT);
        context.startActivity(intent);
    }

    public static Intent incomingIntent(Context context, long callId, String peerName) {
        return incomingIntent(context, callId, peerName, "audio");
    }

    public static Intent incomingIntent(Context context, long callId, String peerName, String callType) {
        return incomingIntent(context, callId, peerName, "", callType);
    }

    public static Intent incomingIntent(Context context, long callId, String peerName, String peerAvatar,
                                        String callType) {
        return new Intent(context, VoiceCallActivity.class)
            .putExtra(EXTRA_CALL_ID, callId)
            .putExtra(EXTRA_PEER_NAME, peerName)
            .putExtra(EXTRA_PEER_AVATAR, peerAvatar == null ? "" : peerAvatar)
            .putExtra(EXTRA_CALL_TYPE, callType)
            .putExtra(EXTRA_INCOMING, true)
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_SINGLE_TOP | Intent.FLAG_ACTIVITY_CLEAR_TOP);
    }

    public static Intent resumeIntent(Context context, long callId, String peerName) {
        return resumeIntent(context, callId, peerName, "audio");
    }

    public static Intent resumeIntent(Context context, long callId, String peerName, String callType) {
        return resumeIntent(context, callId, peerName, "", callType);
    }

    public static Intent resumeIntent(Context context, long callId, String peerName, String peerAvatar,
                                      String callType) {
        return new Intent(context, VoiceCallActivity.class)
            .putExtra(EXTRA_CALL_ID, callId)
            .putExtra(EXTRA_PEER_NAME, peerName)
            .putExtra(EXTRA_PEER_AVATAR, peerAvatar == null ? "" : peerAvatar)
            .putExtra(EXTRA_CALL_TYPE, callType)
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_REORDER_TO_FRONT | Intent.FLAG_ACTIVITY_SINGLE_TOP);
    }

    @Override protected void onCreate(Bundle state) {
        super.onCreate(state);
        binding = ActivityVoiceCallBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());
        setVolumeControlStream(AudioManager.STREAM_VOICE_CALL);
        callId = getIntent().getLongExtra(EXTRA_CALL_ID, 0);
        peerId = getIntent().getLongExtra(EXTRA_PEER_ID, 0);
        String initialName = getIntent().getStringExtra(EXTRA_PEER_NAME);
        if (initialName != null && !initialName.trim().isEmpty()) peerName = initialName.trim();
        String initialAvatar = getIntent().getStringExtra(EXTRA_PEER_AVATAR);
        if (initialAvatar != null) peerAvatar = initialAvatar;
        String initialType = getIntent().getStringExtra(EXTRA_CALL_TYPE);
        if ("video".equals(initialType)) callType = "video";
        String initialContextType = getIntent().getStringExtra(EXTRA_CONTEXT_TYPE);
        contextType = initialContextType == null || initialContextType.trim().isEmpty()
            ? "private" : initialContextType.trim();
        contextId = getIntent().getLongExtra(EXTRA_CONTEXT_ID, 0);
        String initialContextName = getIntent().getStringExtra(EXTRA_CONTEXT_NAME);
        if (initialContextName != null) contextName = initialContextName.trim();
        getWindow().addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON);
        if (getIntent().getBooleanExtra(EXTRA_INCOMING, false)) {
            getWindow().addFlags(WindowManager.LayoutParams.FLAG_SHOW_WHEN_LOCKED
                | WindowManager.LayoutParams.FLAG_TURN_SCREEN_ON
                | WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON);
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O_MR1) {
                setShowWhenLocked(true);
                setTurnScreenOn(true);
            }
        }
        binding.peerName.setText(peerName);
        renderAvatar();
        binding.backButton.setOnClickListener(view -> handleBackOrMinimize());
        binding.minimizeButton.setOnClickListener(view -> enterCallPictureInPicture());
        binding.answerButton.setOnClickListener(view -> requestAnswer());
        binding.declineButton.setOnClickListener(view -> decline());
        bindCallControl(binding.microphoneControl, this::toggleMicrophone);
        bindCallControl(binding.speakerControl, this::toggleSpeaker);
        bindCallControl(binding.cameraSwitchControl, () -> {
            if (engine != null) engine.switchCamera();
        });
        bindCallControl(binding.cameraToggleControl, this::toggleCamera);
        bindCallControl(binding.hangupControl, this::hangup);
        centerCallIcon(binding.microphoneButton, 26);
        centerCallIcon(binding.speakerButton, 26);
        centerCallIcon(binding.cameraToggleButton, 26);
        centerCallIcon(binding.cameraSwitchButton, 26);
        centerCallIcon(binding.hangupButton, 28);
        binding.videoStage.setOnClickListener(view -> toggleImmersiveControls());
        binding.callBody.setOnClickListener(view -> toggleImmersiveControls());
        binding.remotePreviewContainer.setOnClickListener(view -> swapVideoFocus());
        binding.videoSwapTarget.setOnClickListener(view -> swapVideoFocus());
        configureVideoPreviewDrag();
        binding.localVideo.setOnClickListener(view -> toggleImmersiveControls());
        getOnBackPressedDispatcher().addCallback(this, new OnBackPressedCallback(true) {
            @Override public void handleOnBackPressed() {
                handleBackOrMinimize();
            }
        });
        ContextCompat.registerReceiver(this, endedReceiver,
            new IntentFilter(VoiceCallForegroundService.ACTION_LOCAL_ENDED), ContextCompat.RECEIVER_NOT_EXPORTED);
        registeredReceiver = true;
        renderSpeakerState();
        registerSystemCallMonitor();
        renderCallType(false);
        showIntro();
        handler.post(durationTicker);
        if (callId > 0) {
            binding.progress.setVisibility(View.VISIBLE);
            loadState();
        } else if (peerId > 0) {
            ensureCallPermissions(false);
        } else {
            message("缺少通话对象");
            finishCall("未能发起通话", true);
        }
    }

    @Override protected void onNewIntent(Intent intent) {
        super.onNewIntent(intent);
        setIntent(intent);
        boolean outgoing = !intent.getBooleanExtra(EXTRA_INCOMING, false)
            && intent.getLongExtra(EXTRA_PEER_ID, 0) > 0;
        if (outgoing) {
            if (!terminal && isSameOutgoingTarget(intent)) {
                updateOutgoingPresentation(intent);
                if (callId <= 0 && createRequest == null) ensureCallPermissions(false);
                return;
            }
            if (terminal) {
                handler.removeCallbacks(closeAfterCall);
                restartOutgoingFromIntent(intent);
                return;
            }
            long requestedPeer = intent.getLongExtra(EXTRA_PEER_ID, 0);
            if (callId > 0 && peerId > 0 && requestedPeer != peerId) {
                message("当前已有一通通话，请先结束后再呼叫其他人");
                return;
            }
            restartOutgoingFromIntent(intent);
            return;
        }
        if (intent.getBooleanExtra(EXTRA_INCOMING, false)) {
            getWindow().addFlags(WindowManager.LayoutParams.FLAG_SHOW_WHEN_LOCKED
                | WindowManager.LayoutParams.FLAG_TURN_SCREEN_ON | WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON);
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O_MR1) {
                setShowWhenLocked(true);
                setTurnScreenOn(true);
            }
        }
        String incomingName = intent.getStringExtra(EXTRA_PEER_NAME);
        if (incomingName != null && !incomingName.trim().isEmpty()) peerName = incomingName.trim();
        String incomingAvatar = intent.getStringExtra(EXTRA_PEER_AVATAR);
        if (incomingAvatar != null && !incomingAvatar.trim().isEmpty()) peerAvatar = incomingAvatar.trim();
        binding.peerName.setText(peerName);
        renderAvatar();
        String incomingType = intent.getStringExtra(EXTRA_CALL_TYPE);
        callType = "video".equals(incomingType) ? "video" : "audio";
        renderCallType(false);
        showIntro();
        long incomingCallId = intent.getLongExtra(EXTRA_CALL_ID, 0);
        if (incomingCallId > 0 && incomingCallId != callId) {
            closeEngine();
            callId = incomingCallId;
            lastSignalId = 0;
            terminal = false;
            callConnected = false;
            callControlsHidden = false;
            localVideoLarge = true;
            remoteVideoAvailable = false;
            remoteCameraEnabled = true;
            loadState();
        }
    }

    private void restartOutgoingFromIntent(Intent intent) {
        handler.removeCallbacks(statePoller);
        handler.removeCallbacks(signalPoller);
        handler.removeCallbacks(signalSender);
        handler.removeCallbacks(reconnectPeer);
        if (createRequest != null) { createRequest.cancel(); createRequest = null; }
        if (stateRequest != null) { stateRequest.cancel(); stateRequest = null; }
        if (signalRequest != null) { signalRequest.cancel(); signalRequest = null; }
        if (signalSendRequest != null) { signalSendRequest.cancel(); signalSendRequest = null; }
        pendingSignals.clear();
        closeEngine();
        previewEngine = false;

        callId = 0;
        durationClock.reset();
        peerId = intent.getLongExtra(EXTRA_PEER_ID, peerId);
        String requestedName = intent.getStringExtra(EXTRA_PEER_NAME);
        if (requestedName != null && !requestedName.trim().isEmpty()) peerName = requestedName.trim();
        String requestedAvatar = intent.getStringExtra(EXTRA_PEER_AVATAR);
        if (requestedAvatar != null) peerAvatar = requestedAvatar;
        callType = "video".equals(intent.getStringExtra(EXTRA_CALL_TYPE)) ? "video" : "audio";
        String requestedContext = intent.getStringExtra(EXTRA_CONTEXT_TYPE);
        contextType = requestedContext == null || requestedContext.trim().isEmpty() ? "private" : requestedContext.trim();
        contextId = intent.getLongExtra(EXTRA_CONTEXT_ID, 0);
        String requestedContextName = intent.getStringExtra(EXTRA_CONTEXT_NAME);
        contextName = requestedContextName == null ? "" : requestedContextName.trim();
        direction = "outgoing";
        status = "";
        lastSignalId = 0;
        stateReadFailures = 0;
        reconnectAttempts = 0;
        callConnected = false;
        mediaEverConnected = false;
        terminal = false;
        endingLocally = false;
        resumeOffer = false;
        localOfferOwner = false;
        remoteVideoAvailable = false;
        remoteCameraEnabled = true;
        callControlsHidden = false;
        localVideoLarge = true;
        foregroundStarted = false;
        renderedAvatarKey = null;
        lastVideoRenderState = "";
        binding.peerName.setText(peerName);
        renderAvatar();
        renderCallType(false);
        binding.status.setVisibility(View.VISIBLE);
        binding.status.setAlpha(1f);
        binding.status.setText("正在重新接入当前通话");
        binding.progress.setVisibility(View.VISIBLE);
        ensureCallPermissions(false);
    }

    private boolean isSameOutgoingTarget(Intent intent) {
        long requestedPeer = intent.getLongExtra(EXTRA_PEER_ID, 0);
        String requestedType = "video".equals(intent.getStringExtra(EXTRA_CALL_TYPE)) ? "video" : "audio";
        String requestedContext = intent.getStringExtra(EXTRA_CONTEXT_TYPE);
        requestedContext = requestedContext == null || requestedContext.trim().isEmpty()
            ? "private" : requestedContext.trim();
        return requestedPeer > 0 && requestedPeer == peerId
            && requestedType.equals(callType)
            && requestedContext.equals(contextType)
            && intent.getLongExtra(EXTRA_CONTEXT_ID, 0) == contextId;
    }

    private void updateOutgoingPresentation(Intent intent) {
        String requestedName = intent.getStringExtra(EXTRA_PEER_NAME);
        if (requestedName != null && !requestedName.trim().isEmpty()) peerName = requestedName.trim();
        String requestedAvatar = intent.getStringExtra(EXTRA_PEER_AVATAR);
        if (requestedAvatar != null && !requestedAvatar.equals(peerAvatar)) {
            peerAvatar = requestedAvatar;
            renderedAvatarKey = null;
        }
        binding.peerName.setText(peerName);
        renderAvatar();
    }

    @Override protected void onResume() {
        super.onResume();
        if (terminal || engine == null) return;
        configureAudioManager();
        setSpeakerEnabled(speakerEnabled);
        engine.ensureAudioActive();
    }

    private void createCall() {
        if (createRequest != null) return;
        binding.progress.setVisibility(View.VISIBLE);
        binding.status.setText("正在呼叫 " + peerName);
        JsonObject body = new JsonObject();
        body.addProperty("to_user_id", peerId);
        body.addProperty("call_type", callType);
        body.addProperty("context_type", contextType);
        if (contextId > 0) body.addProperty("context_id", contextId);
        if (!contextName.isEmpty()) body.addProperty("context_name", contextName);
        createRequest = AppAccess.from(this).repository().post("/api/user/voice-calls", body, result -> {
            createRequest = null;
            if (binding == null) return;
            binding.progress.setVisibility(View.GONE);
            if (!result.isSuccessful()) {
                message(result.message().isEmpty()
                    ? (isVideoCall() ? "发起视频通话失败" : "发起语音通话失败")
                    : result.message());
                finishCall("未能发起通话", true);
                return;
            }
            applyCall(Jsons.object(result.dataObject(), "call"));
        });
    }

    private void startOutgoingCall() {
        if (callId > 0 || terminal || createRequest != null) return;
        if (binding != null) {
            // Keep the first tap responsive: warm the local preview while the
            // server creates the call instead of waiting for that round trip.
            binding.getRoot().post(this::prepareOutgoingEngine);
        }
        createCall();
    }

    private void loadState() {
        handler.removeCallbacks(statePoller);
        if (callId <= 0 || terminal || stateRequest != null) return;
        stateRequest = AppAccess.from(this).repository().get("/api/user/voice-calls/" + callId,
            new LinkedHashMap<>(), result -> {
                stateRequest = null;
                if (binding == null || terminal) return;
                binding.progress.setVisibility(View.GONE);
                if (!result.isSuccessful()) {
                    if (result.httpCode() == 404 || result.httpCode() == 410) {
                        finishCall("通话已结束", true);
                        return;
                    }
                    stateReadFailures++;
                    if (stateReadFailures == 1 || stateReadFailures % 4 == 0) {
                        binding.status.setVisibility(View.VISIBLE);
                        binding.status.setAlpha(1f);
                        binding.status.setText("网络波动，正在同步通话状态");
                    }
                    handler.postDelayed(statePoller, Math.min(2500L, 500L * stateReadFailures));
                    return;
                }
                stateReadFailures = 0;
                applyCall(Jsons.object(result.dataObject(), "call"));
                if (!terminal) handler.postDelayed(statePoller, statePollMs);
            });
    }

    private void applyCall(JsonObject call) {
        if (call == null || call.entrySet().isEmpty()) return;
        callId = Jsons.longValue(call, "id");
        peerId = Jsons.longValue(call, "peer_user_id");
        String name = Jsons.string(call, "peer_name");
        if (!name.isEmpty()) peerName = name;
        peerAvatar = Jsons.string(call, "peer_avatar");
        direction = Jsons.string(call, "direction");
        status = Jsons.string(call, "status");
        resumeOffer = resumeOffer || booleanValue(call, "resume_offer");
        String serverCallType = Jsons.string(call, "call_type");
        callType = "video".equals(serverCallType) ? "video" : "audio";
        String serverContextType = Jsons.string(call, "context_type");
        if (!serverContextType.isEmpty()) contextType = serverContextType;
        contextId = Jsons.longValue(call, "context_id");
        String serverContextName = Jsons.string(call, "context_name");
        if (!serverContextName.isEmpty()) contextName = serverContextName;
        long configuredPoll = Jsons.longValue(call, "signal_poll_ms");
        signalPollMs = configuredPoll <= 0 ? 100L : Math.max(80L, Math.min(5000L, configuredPoll));
        iceServers = Jsons.array(call, "ice_servers");
        long now = SystemClock.elapsedRealtime();
        long serverDuration = Jsons.longValue(call, "current_duration_seconds");
        boolean established = "active".equals(status) || callConnected || mediaEverConnected;
        durationClock.sync(serverDuration, established, now);
        binding.peerName.setText(peerName);
        renderAvatar();
        renderStatus(Jsons.string(call, "status_label"));
        terminal = booleanValue(call, "is_terminal");
        if (terminal) {
            durationClock.stop(serverDuration, now);
            finishCall(Jsons.string(call, "status_label"), true);
            return;
        }
        boolean incomingRinging = "incoming".equals(direction) && "ringing".equals(status)
            && !mediaEverConnected;
        renderCallType(incomingRinging);
        binding.incomingControls.setVisibility(incomingRinging ? View.VISIBLE : View.GONE);
        binding.activeControls.setVisibility(incomingRinging || callControlsHidden ? View.GONE : View.VISIBLE);
        binding.duration.setVisibility(established && !callControlsHidden
            ? View.VISIBLE : View.INVISIBLE);
        if (established) ensureForegroundForConnectedCall();
        if (!incomingRinging && hasRequiredPermissions()) initializeEngine();
        handler.removeCallbacks(signalPoller);
        handler.post(signalPoller);
        updatePictureInPictureParams();
    }

    private void renderStatus(String serverLabel) {
        if (callConnected || mediaEverConnected || "active".equals(status)) {
            binding.status.setText(engine == null
                ? (isVideoCall() ? "正在建立视频连接" : "正在建立语音连接")
                : (isVideoCall() ? "视频通话中" : "语音通话中"));
        } else if ("ringing".equals(status)) {
            binding.status.setText("incoming".equals(direction)
                ? (isVideoCall() ? "邀请你进行视频通话" : "邀请你进行语音通话")
                : "正在等待对方接听");
        } else {
            binding.status.setText(serverLabel.isEmpty() ? "通话状态已更新" : serverLabel);
        }
    }

    private void requestAnswer() {
        if (actionRequest != null || terminal) return;
        ensureCallPermissions(true);
    }

    private void answer() {
        if (callId <= 0 || actionRequest != null) return;
        MessageNotificationService.cancelIncomingCall(this, callId);
        binding.progress.setVisibility(View.VISIBLE);
        binding.status.setText("正在接通");
        binding.incomingControls.setVisibility(View.GONE);
        binding.activeControls.setVisibility(View.VISIBLE);
        renderCallType(false);
        // Start receiving the caller's offer immediately. Waiting for the
        // answer API before creating WebRTC adds a full network round trip.
        initializeEngine();
        actionRequest = AppAccess.from(this).repository().post("/api/user/voice-calls/" + callId + "/answer",
            new JsonObject(), result -> {
                actionRequest = null;
                if (binding == null) return;
                binding.progress.setVisibility(View.GONE);
                if (!result.isSuccessful()) {
                    message(result.message().isEmpty() ? "接听失败" : result.message());
                    closeEngine();
                    foregroundStarted = false;
                    binding.incomingControls.setVisibility(View.VISIBLE);
                    binding.activeControls.setVisibility(View.GONE);
                    return;
                }
                applyCall(Jsons.object(result.dataObject(), "call"));
            });
    }

    private void decline() {
        if (callId <= 0 || actionRequest != null) return;
        endingLocally = true;
        long endingCallId = callId;
        finishCall("未接", true);
        actionRequest = AppAccess.from(this).repository().post("/api/user/voice-calls/" + endingCallId + "/decline",
            new JsonObject(), result -> {
                actionRequest = null;
            });
    }

    private void hangup() {
        if (callId <= 0 || endingLocally) { finishCall("通话已结束", true); return; }
        endingLocally = true;
        long endingCallId = callId;
        hangupDeliveryPending = true;
        VoiceCallForegroundService.requestHangup(this, endingCallId,
            durationClock.seconds(SystemClock.elapsedRealtime()));
        finishCall("通话已结束", true);
    }

    private void initializeEngine() {
        boolean upgradingPreview = previewEngine && engine != null;
        if ((engine != null && !upgradingPreview) || terminal || callId <= 0 || !hasRequiredPermissions()) return;
        try {
            configureAudioManager();
            if (upgradingPreview) {
                engine.updateIceServers(iceServers);
                previewEngine = false;
            } else {
                engine = new VoiceCallEngine(this, iceServers, this, isVideoCall(),
                    isVideoCall() ? binding.localVideo : null,
                    isVideoCall() ? binding.remoteVideo : null);
            }
            engine.setMicrophoneEnabled(microphoneEnabled);
            engine.ensureAudioActive();
            engine.setCameraEnabled(cameraEnabled);
            engine.setLocalVideoLarge(localVideoLarge);
            setSpeakerEnabled(isVideoCall());
            boolean shouldCreateOffer = resumeOffer || "outgoing".equals(direction);
            localOfferOwner = shouldCreateOffer;
            resumeOffer = false;
            if (shouldCreateOffer) engine.createOffer();
            // Media state also carries the local system-phone busy flag for
            // audio calls, so the other participant always receives the notice.
            sendCameraState();
            handler.removeCallbacks(signalPoller);
            handler.post(signalPoller);
            handler.postDelayed(() -> {
                if (engine != null && !terminal) {
                    setSpeakerEnabled(speakerEnabled);
                    engine.ensureAudioActive();
                }
            }, 250L);
            renderVideoCameraState();
        } catch (RuntimeException | UnsatisfiedLinkError error) {
            message("无法启动网络通话组件：" + error.getMessage());
            finishCall("未能发起通话", true);
        }
    }

    private void prepareOutgoingEngine() {
        if (engine != null || callId > 0 || terminal || !hasRequiredPermissions()) return;
        try {
            configureAudioManager();
            engine = new VoiceCallEngine(this, new JsonArray(), this, isVideoCall(),
                isVideoCall() ? binding.localVideo : null,
                isVideoCall() ? binding.remoteVideo : null);
            previewEngine = true;
            engine.setMicrophoneEnabled(false);
            if (isVideoCall()) {
                engine.setCameraEnabled(cameraEnabled);
                localVideoLarge = true;
                binding.videoStage.setVisibility(View.VISIBLE);
                binding.status.setText("正在准备视频通话");
                renderVideoCameraState();
            }
        } catch (RuntimeException | UnsatisfiedLinkError error) {
            message(isVideoCall() ? "无法打开本机摄像头：" + error.getMessage()
                : "无法准备语音通话：" + error.getMessage());
            finishCall("未能发起通话", true);
        }
    }

    private void loadSignals() {
        handler.removeCallbacks(signalPoller);
        if (callId <= 0 || terminal || signalRequest != null) return;
        // 来电响铃时引擎尚未创建，不能提前消费 offer/ICE，否则接听后将永远收不到媒体轨道。
        if (engine == null) return;
        LinkedHashMap<String, String> query = new LinkedHashMap<>();
        query.put("after_id", String.valueOf(lastSignalId));
        signalRequest = AppAccess.from(this).repository().get("/api/user/voice-calls/" + callId + "/signals", query, result -> {
            signalRequest = null;
            if (binding == null || terminal) return;
            if (result.isSuccessful()) {
                for (JsonElement element : result.items()) {
                    if (!element.isJsonObject()) continue;
                    JsonObject signal = element.getAsJsonObject();
                    lastSignalId = Math.max(lastSignalId, Jsons.longValue(signal, "id"));
                    if (engine != null) engine.acceptSignal(Jsons.string(signal, "signal_type"), Jsons.object(signal, "payload"));
                }
            }
            handler.postDelayed(signalPoller, signalPollMs);
        });
    }

    @Override public void onSignal(String type, JsonObject payload) {
        JsonObject item = new JsonObject();
        item.addProperty("signal_type", type);
        item.add("payload", payload == null ? new JsonObject() : payload.deepCopy());
        handler.post(() -> {
            if (callId <= 0 || terminal) return;
            pendingSignals.addLast(item);
            handler.removeCallbacks(signalSender);
            handler.postDelayed(signalSender, "ice".equals(type) ? 12L : 0L);
        });
    }

    private void flushPendingSignals() {
        if (callId <= 0 || terminal || signalSendRequest != null || pendingSignals.isEmpty()) return;
        List<JsonObject> inFlight = new ArrayList<>();
        while (!pendingSignals.isEmpty() && inFlight.size() < 64) inFlight.add(pendingSignals.removeFirst());
        JsonArray items = new JsonArray();
        for (JsonObject item : inFlight) items.add(item);
        JsonObject body = new JsonObject();
        body.add("items", items);
        signalSendRequest = AppAccess.from(this).repository().post(
            "/api/user/voice-calls/" + callId + "/signals", body, result -> {
                signalSendRequest = null;
                if (binding == null || terminal) return;
                if (!result.isSuccessful()) {
                    for (int index = inFlight.size() - 1; index >= 0; index--) {
                        pendingSignals.addFirst(inFlight.get(index));
                    }
                    message(result.message().isEmpty() ? "发送通话信令失败" : result.message());
                    handler.postDelayed(signalSender, 180L);
                    return;
                }
                if (!pendingSignals.isEmpty()) handler.post(signalSender);
            });
    }

    @Override public void onConnectionState(String text, boolean connected) {
        runOnUiThread(() -> {
            if (binding == null || terminal) return;
            binding.status.setText(text);
            if (connected) {
                boolean firstConnection = !mediaEverConnected;
                callConnected = true;
                mediaEverConnected = true;
                durationClock.sync(0L, true, SystemClock.elapsedRealtime());
                reconnectAttempts = 0;
                handler.removeCallbacks(reconnectPeer);
                configureAudioManager();
                setSpeakerEnabled(speakerEnabled);
                if (engine != null) engine.ensureAudioActive();
                binding.duration.setVisibility(View.VISIBLE);
                binding.peerName.setVisibility(View.GONE);
                binding.avatar.setVisibility(View.GONE);
                ensureForegroundForConnectedCall();
                if (isVideoCall()) renderVideoCameraState();
                if (firstConnection) {
                    binding.status.animate().alpha(0f).setDuration(350L).withEndAction(() -> {
                        if (binding != null && callConnected && !callControlsHidden) binding.status.setVisibility(View.GONE);
                    }).start();
                } else if (!callControlsHidden) {
                    binding.status.setVisibility(View.GONE);
                }
            } else {
                // ICE can briefly disconnect while switching Wi-Fi/mobile networks. Keep the
                // established call surface alive until the backend reports a terminal state.
                callConnected = mediaEverConnected;
                binding.status.animate().cancel();
                binding.status.setAlpha(1f);
                binding.status.setVisibility(View.VISIBLE);
                if (localOfferOwner && engine != null) {
                    handler.removeCallbacks(reconnectPeer);
                    handler.postDelayed(reconnectPeer, 350L);
                }
            }
        });
    }

    private void bindCallControl(View control, Runnable action) {
        if (control == null) return;
        control.setOnClickListener(view -> action.run());
        if (!(control instanceof android.view.ViewGroup)) return;
        android.view.ViewGroup group = (android.view.ViewGroup) control;
        for (int index = 0; index < group.getChildCount(); index++) {
            View child = group.getChildAt(index);
            child.setClickable(true);
            child.setFocusable(false);
            child.setOnClickListener(view -> control.performClick());
        }
    }

    private void ensureForegroundForConnectedCall() {
        if (foregroundStarted || callId <= 0 || terminal) return;
        MessageNotificationService.cancelIncomingCall(this, callId);
        VoiceCallForegroundService.start(this, callId, peerName, callType,
            durationClock.seconds(SystemClock.elapsedRealtime()));
        foregroundStarted = true;
    }

    private void centerCallIcon(MaterialButton button, int iconSizeDp) {
        if (button == null) return;
        button.setGravity(Gravity.CENTER);
        button.setIconGravity(MaterialButton.ICON_GRAVITY_TEXT_START);
        button.setIconPadding(0);
        button.setIconSize(dp(iconSizeDp));
        button.setCornerRadius(dp(100));
        button.setPadding(0, 0, 0, 0);
        button.setInsetTop(0);
        button.setInsetBottom(0);
        button.setMinWidth(0);
        button.setMinimumWidth(0);
        button.setMinHeight(0);
        button.setMinimumHeight(0);
        button.post(() -> {
            if (button.getWidth() <= 0 || button.getHeight() <= 0) return;
            button.setCornerRadius(Math.min(button.getWidth(), button.getHeight()) / 2);
        });
    }

    private void configureVideoPreviewDrag() {
        videoPreviewTouchSlop = ViewConfiguration.get(this).getScaledTouchSlop();
        binding.videoSwapTarget.setOnTouchListener((view, event) -> {
            if (!isVideoCall() || terminal || view.getVisibility() != View.VISIBLE) return false;
            switch (event.getActionMasked()) {
                case MotionEvent.ACTION_DOWN:
                    videoPreviewDragging = false;
                    videoPreviewDownX = event.getRawX();
                    videoPreviewDownY = event.getRawY();
                    videoPreviewStartX = view.getTranslationX();
                    videoPreviewStartY = view.getTranslationY();
                    view.performHapticFeedback(HapticFeedbackConstants.CLOCK_TICK);
                    return true;
                case MotionEvent.ACTION_MOVE:
                    float dx = event.getRawX() - videoPreviewDownX;
                    float dy = event.getRawY() - videoPreviewDownY;
                    if (!videoPreviewDragging && Math.hypot(dx, dy) >= videoPreviewTouchSlop) {
                        videoPreviewDragging = true;
                    }
                    if (videoPreviewDragging) {
                        moveVideoPreview(videoPreviewStartX + dx, videoPreviewStartY + dy, false);
                    }
                    return true;
                case MotionEvent.ACTION_UP:
                    if (videoPreviewDragging) {
                        snapVideoPreviewToEdge();
                    } else {
                        view.performClick();
                    }
                    videoPreviewDragging = false;
                    return true;
                case MotionEvent.ACTION_CANCEL:
                    if (videoPreviewDragging) snapVideoPreviewToEdge();
                    videoPreviewDragging = false;
                    return true;
                default:
                    return false;
            }
        });
    }

    private void moveVideoPreview(float requestedX, float requestedY, boolean animate) {
        if (binding == null || binding.videoStage.getWidth() <= 0 || binding.videoSwapTarget.getWidth() <= 0) return;
        View target = binding.videoSwapTarget;
        int edge = dp(12);
        int topInset = dp(56);
        int bottomInset = callControlsHidden ? dp(20) : dp(132);
        float minX = -target.getLeft() + edge;
        float maxX = binding.videoStage.getWidth() - target.getRight() - edge;
        float minY = -target.getTop() + topInset;
        float maxY = binding.videoStage.getHeight() - target.getBottom() - bottomInset;
        float x = Math.max(minX, Math.min(maxX, requestedX));
        float y = Math.max(minY, Math.min(maxY, requestedY));
        if (animate) {
            DecelerateInterpolator interpolator = new DecelerateInterpolator();
            target.animate().translationX(x).translationY(y).setDuration(180L).setInterpolator(interpolator).start();
            binding.remotePreviewContainer.animate().translationX(x).translationY(y)
                .setDuration(180L).setInterpolator(interpolator).start();
        } else {
            target.setTranslationX(x);
            target.setTranslationY(y);
            binding.remotePreviewContainer.setTranslationX(x);
            binding.remotePreviewContainer.setTranslationY(y);
        }
    }

    private void snapVideoPreviewToEdge() {
        if (binding == null || binding.videoStage.getWidth() <= 0) return;
        View target = binding.videoSwapTarget;
        int edge = dp(12);
        float left = -target.getLeft() + edge;
        float right = binding.videoStage.getWidth() - target.getRight() - edge;
        float center = target.getLeft() + target.getTranslationX() + target.getWidth() / 2f;
        moveVideoPreview(center < binding.videoStage.getWidth() / 2f ? left : right,
            target.getTranslationY(), true);
    }

    @Override public void onRemoteVideoAvailable() {
        runOnUiThread(() -> {
            if (binding == null || terminal || !isVideoCall()) return;
            remoteVideoAvailable = true;
            binding.status.setText("视频通话中");
            renderVideoCameraState();
        });
    }

    @Override public void onRemoteCameraState(boolean enabled) {
        runOnUiThread(() -> {
            remoteCameraEnabled = enabled;
            renderVideoCameraState();
        });
    }

    @Override public void onRemoteSystemPhoneBusy(boolean busy) {
        runOnUiThread(() -> {
            if (binding == null || terminal || remoteSystemPhoneBusy == busy) return;
            remoteSystemPhoneBusy = busy;
            binding.status.animate().cancel();
            binding.status.setAlpha(1f);
            binding.status.setVisibility(View.VISIBLE);
            if (busy) {
                binding.status.setText("对方正在拨打电话，请稍后");
            } else if (callConnected) {
                binding.status.setText(isVideoCall() ? "视频通话中" : "语音通话中");
                binding.status.animate().alpha(0f).setStartDelay(800L).setDuration(300L)
                    .withEndAction(() -> {
                        if (binding != null && callConnected && !remoteSystemPhoneBusy) {
                            binding.status.setVisibility(View.GONE);
                        }
                    }).start();
            }
        });
    }

    @Override public void onLocalCameraChanged(boolean frontCamera) {
        runOnUiThread(() -> {
            if (binding == null || terminal || !isVideoCall()) return;
            sendCameraState();
        });
    }

    @Override public void onError(String message) {
        runOnUiThread(() -> {
            if (binding == null || terminal) return;
            binding.status.setText(message);
            Snackbar.make(binding.getRoot(), message, Snackbar.LENGTH_LONG).show();
            boolean fatal = message != null
                && !message.startsWith("切换摄像头失败")
                && !message.startsWith("当前设备无法切换摄像头")
                && (message.contains("连接失败") || message.contains("邀请失败")
                    || message.contains("应答失败") || message.contains("通话信息失败")
                    || message.contains("通话组件") || message.contains("保存本机"));
            if (fatal) finishCall(callConnected ? "通话已断开" : "未能发起通话", true);
        });
    }

    private void toggleMicrophone() {
        if (systemPhoneBusy && !microphoneEnabled) {
            message("系统电话进行中，已自动关闭麦克风");
            return;
        }
        setMicrophoneEnabled(!microphoneEnabled);
    }

    private void setMicrophoneEnabled(boolean enabled) {
        microphoneEnabled = enabled;
        if (engine != null) engine.setMicrophoneEnabled(enabled);
        if (binding != null) {
            binding.microphoneButton.setIconResource(enabled ? R.drawable.ic_mic : R.drawable.ic_mic_off);
            binding.microphoneLabel.setText(enabled ? "麦克风" : "已静音");
        }
    }

    private void toggleSpeaker() {
        setSpeakerEnabled(!speakerEnabled);
    }

    private void toggleCamera() {
        if (!isVideoCall()) return;
        cameraEnabled = !cameraEnabled;
        if (engine != null) engine.setCameraEnabled(cameraEnabled);
        binding.cameraToggleButton.setIconResource(cameraEnabled ? R.drawable.ic_videocam : R.drawable.ic_videocam_off);
        binding.cameraToggleLabel.setText(cameraEnabled ? "摄像头" : "已关闭");
        renderVideoCameraState();
        sendCameraState();
    }

    private void sendCameraState() {
        if (callId <= 0 || terminal) return;
        JsonObject payload = new JsonObject();
        if (isVideoCall()) {
            payload.addProperty("camera_enabled", cameraEnabled);
            payload.addProperty("front_camera", engine == null || engine.isFrontCamera());
        }
        payload.addProperty("system_phone_busy", systemPhoneBusy);
        onSignal("media", payload);
    }

    private void configureAudioManager() {
        if (audioManager != null) return;
        audioManager = (AudioManager) getSystemService(AUDIO_SERVICE);
        if (audioManager == null) return;
        previousAudioMode = audioManager.getMode();
        previousSpeaker = audioManager.isSpeakerphoneOn();
        previousMicrophoneMute = audioManager.isMicrophoneMute();
        AudioAttributes attributes = new AudioAttributes.Builder()
            .setUsage(AudioAttributes.USAGE_VOICE_COMMUNICATION)
            .setContentType(AudioAttributes.CONTENT_TYPE_SPEECH)
            .build();
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            audioFocusRequest = new AudioFocusRequest.Builder(AudioManager.AUDIOFOCUS_GAIN_TRANSIENT)
                .setAudioAttributes(attributes)
                .setAcceptsDelayedFocusGain(false)
                .setOnAudioFocusChangeListener(change -> {
                    if (change == AudioManager.AUDIOFOCUS_GAIN && engine != null && !terminal) {
                        handler.post(() -> {
                            if (engine == null || terminal) return;
                            setSpeakerEnabled(speakerEnabled);
                            engine.ensureAudioActive();
                        });
                    }
                })
                .build();
            audioFocusGranted = audioManager.requestAudioFocus(audioFocusRequest) == AudioManager.AUDIOFOCUS_REQUEST_GRANTED;
        } else {
            //noinspection deprecation
            audioFocusGranted = audioManager.requestAudioFocus(null, AudioManager.STREAM_VOICE_CALL,
                AudioManager.AUDIOFOCUS_GAIN_TRANSIENT) == AudioManager.AUDIOFOCUS_REQUEST_GRANTED;
        }
        audioManager.setMode(AudioManager.MODE_IN_COMMUNICATION);
        audioManager.setMicrophoneMute(false);
        setVolumeControlStream(AudioManager.STREAM_VOICE_CALL);
        setSpeakerEnabled(isVideoCall());
    }

    private void setSpeakerEnabled(boolean enabled) {
        speakerEnabled = enabled;
        if (audioManager == null) audioManager = (AudioManager) getSystemService(AUDIO_SERVICE);
        if (audioManager != null) {
            audioManager.setMode(AudioManager.MODE_IN_COMMUNICATION);
            audioManager.setMicrophoneMute(false);
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
                AudioDeviceInfo desired = null;
                for (AudioDeviceInfo device : audioManager.getAvailableCommunicationDevices()) {
                    if (enabled && device.getType() == AudioDeviceInfo.TYPE_BUILTIN_SPEAKER) { desired = device; break; }
                    if (!enabled && device.getType() == AudioDeviceInfo.TYPE_BUILTIN_EARPIECE) desired = device;
                }
                boolean routed = desired != null && audioManager.setCommunicationDevice(desired);
                if (!routed) {
                    // Some Android 12+ vendor builds expose no communication device until audio starts.
                    // The legacy route remains the most compatible fallback for the first call frame.
                    //noinspection deprecation
                    audioManager.setSpeakerphoneOn(enabled);
                }
            } else {
                //noinspection deprecation
                audioManager.setSpeakerphoneOn(enabled);
            }
        }
        if (binding != null) {
            renderSpeakerState();
        }
    }

    private void renderSpeakerState() {
        if (binding == null) return;
        binding.speakerButton.setIconResource(speakerEnabled ? R.drawable.ic_volume : R.drawable.ic_volume_off);
        binding.speakerLabel.setText(speakerEnabled ? "扬声器" : "听筒");
    }

    private void ensureCallPermissions(boolean answerAfterGrant) {
        permissionForAnswer = answerAfterGrant;
        boolean requestPhoneState = !hasPhoneStatePermission()
            && !getSharedPreferences("voice_call_permissions", MODE_PRIVATE)
                .getBoolean("phone_state_requested", false);
        if (hasRequiredPermissions() && !requestPhoneState) {
            if (answerAfterGrant) answer();
            else if (callId <= 0) {
                startOutgoingCall();
            } else initializeEngine();
        } else {
            ArrayList<String> permissions = new ArrayList<>();
            permissions.add(Manifest.permission.RECORD_AUDIO);
            if (isVideoCall()) permissions.add(Manifest.permission.CAMERA);
            if (requestPhoneState) {
                permissions.add(Manifest.permission.READ_PHONE_STATE);
                getSharedPreferences("voice_call_permissions", MODE_PRIVATE).edit()
                    .putBoolean("phone_state_requested", true).apply();
            }
            callPermissions.launch(permissions.toArray(new String[0]));
        }
    }

    private boolean hasMicrophonePermission() {
        return ContextCompat.checkSelfPermission(this, Manifest.permission.RECORD_AUDIO) == PackageManager.PERMISSION_GRANTED;
    }

    private boolean hasCameraPermission() {
        return ContextCompat.checkSelfPermission(this, Manifest.permission.CAMERA) == PackageManager.PERMISSION_GRANTED;
    }

    private boolean hasPhoneStatePermission() {
        return ContextCompat.checkSelfPermission(this, Manifest.permission.READ_PHONE_STATE)
            == PackageManager.PERMISSION_GRANTED;
    }

    private boolean hasRequiredPermissions() {
        return hasMicrophonePermission() && (!isVideoCall() || hasCameraPermission());
    }

    @SuppressWarnings("deprecation")
    private void registerSystemCallMonitor() {
        if (!hasPhoneStatePermission() || telephonyManager != null) return;
        telephonyManager = (TelephonyManager) getSystemService(TELEPHONY_SERVICE);
        if (telephonyManager == null) return;
        try {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
                SystemCallStateCallback callback = new SystemCallStateCallback();
                phoneStateCallback = callback;
                telephonyManager.registerTelephonyCallback(getMainExecutor(), callback);
            } else {
                legacyPhoneStateListener = new PhoneStateListener() {
                    @Override public void onCallStateChanged(int state, String phoneNumber) {
                        handleSystemCallState(state);
                    }
                };
                telephonyManager.listen(legacyPhoneStateListener, PhoneStateListener.LISTEN_CALL_STATE);
            }
        } catch (SecurityException ignored) {
            telephonyManager = null;
            phoneStateCallback = null;
            legacyPhoneStateListener = null;
        }
    }

    @SuppressWarnings("deprecation")
    private void unregisterSystemCallMonitor() {
        if (telephonyManager == null) return;
        try {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S && phoneStateCallback != null) {
                telephonyManager.unregisterTelephonyCallback(phoneStateCallback);
            } else if (legacyPhoneStateListener != null) {
                telephonyManager.listen(legacyPhoneStateListener, PhoneStateListener.LISTEN_NONE);
            }
        } catch (RuntimeException ignored) { }
        telephonyManager = null;
        phoneStateCallback = null;
        legacyPhoneStateListener = null;
    }

    private void handleSystemCallState(int state) {
        boolean busy = state == TelephonyManager.CALL_STATE_RINGING
            || state == TelephonyManager.CALL_STATE_OFFHOOK;
        if (systemPhoneBusy == busy) return;
        systemPhoneBusy = busy;
        if (busy) {
            autoMutedForSystemCall = microphoneEnabled;
            if (autoMutedForSystemCall) setMicrophoneEnabled(false);
            if (binding != null && !terminal) {
                binding.status.animate().cancel();
                binding.status.setAlpha(1f);
                binding.status.setVisibility(View.VISIBLE);
                binding.status.setText("检测到系统电话，已自动关闭麦克风");
            }
        } else {
            if (autoMutedForSystemCall) setMicrophoneEnabled(true);
            autoMutedForSystemCall = false;
            if (binding != null && !terminal && callConnected) {
                binding.status.setText(isVideoCall() ? "视频通话中" : "语音通话中");
            }
        }
        sendCameraState();
    }

    @RequiresApi(Build.VERSION_CODES.S)
    private final class SystemCallStateCallback extends TelephonyCallback
        implements TelephonyCallback.CallStateListener {
        @Override public void onCallStateChanged(int state) {
            handleSystemCallState(state);
        }
    }

    private boolean isVideoCall() {
        return "video".equals(callType);
    }

    private void renderCallType(boolean incomingRinging) {
        if (binding == null) return;
        boolean video = isVideoCall();
        boolean accepted = callConnected || "active".equals(status);
        boolean hideIdentity = accepted || (video && !incomingRinging);
        binding.callTypeTitle.setText(video ? "视频通话" : "语音通话");
        binding.networkHint.setText(video
            ? "仅使用网络流量，画面不会自动保存到本地"
            : "仅使用网络流量，不会拨打系统电话或产生话费");
        binding.videoStage.setVisibility(video && !incomingRinging ? View.VISIBLE : View.GONE);
        binding.avatar.setVisibility(hideIdentity ? View.GONE : View.VISIBLE);
        binding.peerName.setVisibility(hideIdentity ? View.GONE : View.VISIBLE);
        binding.cameraToggleControl.setVisibility(video ? View.VISIBLE : View.GONE);
        binding.cameraSwitchControl.setVisibility(video ? View.VISIBLE : View.GONE);
        renderVideoCameraState();
    }

    private void renderAvatar() {
        if (binding == null) return;
        String absolute = ImageLoader.get().absoluteUrl(this, peerAvatar);
        String key = absolute.isEmpty() ? peerAvatar : absolute;
        if (key.equals(renderedAvatarKey)) return;
        renderedAvatarKey = key;
        ImageLoader.get().load(key, binding.avatar, R.drawable.ic_person);
        ImageLoader.get().load(key, binding.pipAvatar, R.drawable.ic_person);
        ImageLoader.get().load(key, binding.videoFallbackAvatar, R.drawable.ic_person);
    }

    private void renderVideoCameraState() {
        if (binding == null || !isVideoCall()) return;
        boolean pip = Build.VERSION.SDK_INT >= Build.VERSION_CODES.N && isInPictureInPictureMode();
        boolean localAvailable = cameraEnabled && engine != null;
        boolean remoteAvailable = callConnected && remoteCameraEnabled && remoteVideoAvailable;
        String renderState = pip + "|" + localAvailable + "|" + remoteAvailable + "|"
            + localVideoLarge + "|" + remoteCameraEnabled + "|" + callConnected;
        if (renderState.equals(lastVideoRenderState)) return;
        lastVideoRenderState = renderState;
        boolean displayLocalLarge;
        if (pip) {
            displayLocalLarge = !remoteAvailable && localAvailable;
        } else if (localAvailable && remoteAvailable) {
            displayLocalLarge = localVideoLarge;
        } else if (localAvailable) {
            displayLocalLarge = true;
        } else {
            displayLocalLarge = false;
        }
        boolean showLargeSlot = localAvailable || remoteAvailable;
        boolean showSmallSlot = !pip && localAvailable && remoteAvailable;
        binding.localVideo.setVisibility(showLargeSlot ? View.VISIBLE : View.GONE);
        binding.remotePreviewContainer.setVisibility(showSmallSlot ? View.VISIBLE : View.GONE);
        binding.remoteVideo.setVisibility(showSmallSlot ? View.VISIBLE : View.GONE);
        binding.remoteCameraFallback.setVisibility(View.GONE);
        binding.remoteCameraState.setText(remoteCameraEnabled ? "正在等待对方画面" : "对方已关闭摄像头");
        if (callConnected) {
            binding.avatar.setVisibility(View.GONE);
            binding.peerName.setVisibility(View.GONE);
        }
        binding.videoSwapTarget.setVisibility(showSmallSlot ? View.VISIBLE : View.GONE);
        if (engine != null) {
            engine.setCompactVideoMode(pip);
            engine.setLocalVideoLarge(displayLocalLarge);
        }
    }

    private void swapVideoFocus() {
        if (!isVideoCall() || terminal || videoFocusAnimating || !cameraEnabled
            || !remoteCameraEnabled || !remoteVideoAvailable) return;
        videoFocusAnimating = true;
        binding.videoSwapTarget.setEnabled(false);
        binding.videoSwapTarget.performHapticFeedback(HapticFeedbackConstants.CLOCK_TICK);
        binding.localVideo.animate().cancel();
        binding.remotePreviewContainer.animate().cancel();
        AccelerateDecelerateInterpolator interpolator = new AccelerateDecelerateInterpolator();
        binding.localVideo.animate().alpha(0.35f).scaleX(0.965f).scaleY(0.965f)
            .setDuration(135L).setInterpolator(interpolator).start();
        binding.remotePreviewContainer.animate().alpha(0.35f).scaleX(1.1f).scaleY(1.1f)
            .setDuration(135L).setInterpolator(interpolator).start();
        handler.postDelayed(() -> {
            if (binding == null || terminal) return;
            localVideoLarge = !localVideoLarge;
            renderVideoCameraState();
            binding.localVideo.setScaleX(1.025f);
            binding.localVideo.setScaleY(1.025f);
            binding.remotePreviewContainer.setScaleX(0.9f);
            binding.remotePreviewContainer.setScaleY(0.9f);
            binding.localVideo.animate().alpha(1f).scaleX(1f).scaleY(1f)
                .setDuration(190L).setInterpolator(new DecelerateInterpolator()).start();
            binding.remotePreviewContainer.animate().alpha(1f).scaleX(1f).scaleY(1f)
                .setDuration(210L).setInterpolator(new DecelerateInterpolator()).withEndAction(() -> {
                    if (binding == null) return;
                    videoFocusAnimating = false;
                    if (!terminal) binding.videoSwapTarget.setEnabled(true);
                }).start();
        }, 140L);
    }

    private void toggleImmersiveControls() {
        if (!isVideoCall() || !callConnected || terminal || isInPictureInPictureMode()) return;
        callControlsHidden = !callControlsHidden;
        applyCallChromeVisibility(true);
    }

    private void applyCallChromeVisibility(boolean animate) {
        if (binding == null || isInPictureInPictureMode()) return;
        boolean incomingRinging = "incoming".equals(direction) && "ringing".equals(status);
        boolean showChrome = !callControlsHidden;
        if (animate) {
            setControlVisibility(binding.topBar, showChrome);
            setControlVisibility(binding.duration, showChrome && "active".equals(status));
            setControlVisibility(binding.activeControls, showChrome && !incomingRinging && !terminal);
        } else {
            binding.topBar.setVisibility(showChrome ? View.VISIBLE : View.INVISIBLE);
            binding.duration.setVisibility(showChrome && "active".equals(status) ? View.VISIBLE : View.INVISIBLE);
            binding.activeControls.setVisibility(showChrome && !incomingRinging && !terminal ? View.VISIBLE : View.INVISIBLE);
        }
        if (callConnected) binding.status.setVisibility(View.GONE);
        if (callConnected) binding.networkHint.setVisibility(View.GONE);
        if (isVideoCall() && callConnected) {
            binding.avatar.setVisibility(View.GONE);
            binding.peerName.setVisibility(View.GONE);
        }
        if (isVideoCall()) moveVideoPreview(binding.videoSwapTarget.getTranslationX(),
            binding.videoSwapTarget.getTranslationY(), true);
    }

    private void setControlVisibility(View view, boolean visible) {
        if (view == null) return;
        view.animate().cancel();
        if (visible) {
            view.setVisibility(View.VISIBLE);
            view.setAlpha(0f);
            view.setTranslationY(dp(visible && view == binding.activeControls ? 8 : 0));
            view.animate().alpha(1f).translationY(0f).setDuration(180L).start();
        } else {
            float targetY = view == binding.activeControls ? dp(8) : 0f;
            view.animate().alpha(0f).translationY(targetY).setDuration(160L).withEndAction(() -> {
                if (binding != null && callControlsHidden) view.setVisibility(View.INVISIBLE);
            }).start();
        }
    }

    private void showIntro() {
        if (binding == null) return;
        introCollapsed = false;
        binding.backButton.setVisibility(View.GONE);
        binding.callTypeTitle.setVisibility(View.VISIBLE);
        binding.networkHint.setVisibility(View.VISIBLE);
        handler.removeCallbacks(collapseIntro);
        handler.postDelayed(collapseIntro, 3000L);
    }

    private void handleBackOrMinimize() {
        if (!terminal && callId > 0 && Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) enterCallPictureInPicture();
        else finish();
    }

    private void finishCall(String text, boolean closeSoon) {
        if (binding == null) return;
        long now = SystemClock.elapsedRealtime();
        durationClock.stop(durationClock.seconds(now), now);
        terminal = true;
        status = "ended";
        handler.removeCallbacks(statePoller);
        handler.removeCallbacks(signalPoller);
        handler.removeCallbacks(reconnectPeer);
        String label = text == null || text.trim().isEmpty() ? "通话已结束" : text.trim();
        if (label.contains("拒绝")) label = "未接";
        binding.status.setText(label);
        binding.incomingControls.setVisibility(View.GONE);
        binding.activeControls.setVisibility(View.GONE);
        closeEngine();
        previewEngine = false;
        if (!hangupDeliveryPending) {
            foregroundStarted = false;
            VoiceCallForegroundService.stop(this);
        }
        MessageNotificationService.cancelIncomingCall(this, callId);
        handler.removeCallbacks(closeAfterCall);
        if (closeSoon) handler.postDelayed(closeAfterCall, 3000L);
    }

    private void closeEngine() {
        if (engine != null) {
            engine.close();
            engine = null;
        }
        lastVideoRenderState = "";
        if (audioManager != null) {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) audioManager.clearCommunicationDevice();
            else {
                //noinspection deprecation
                audioManager.setSpeakerphoneOn(previousSpeaker);
            }
            audioManager.setMicrophoneMute(previousMicrophoneMute);
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O && audioFocusRequest != null) {
                audioManager.abandonAudioFocusRequest(audioFocusRequest);
            } else if (audioFocusGranted) {
                //noinspection deprecation
                audioManager.abandonAudioFocus(null);
            }
            audioFocusRequest = null;
            audioFocusGranted = false;
            audioManager.setMode(previousAudioMode);
            setVolumeControlStream(AudioManager.USE_DEFAULT_STREAM_TYPE);
            audioManager = null;
        }
    }

    private void enterCallPictureInPicture() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O || callId <= 0 || terminal) return;
        try { enterPictureInPictureMode(buildPictureInPictureParams()); }
        catch (RuntimeException error) { message("当前设备无法进入通话小窗"); }
    }

    private void updatePictureInPictureParams() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O && callId > 0) setPictureInPictureParams(buildPictureInPictureParams());
    }

    @RequiresApi(Build.VERSION_CODES.O)
    private PictureInPictureParams buildPictureInPictureParams() {
        PictureInPictureParams.Builder builder = new PictureInPictureParams.Builder()
            .setAspectRatio(isVideoCall() ? new Rational(3, 4) : new Rational(1, 1));
        View source = isVideoCall() ? binding.videoStage : binding.avatar;
        if (source != null && source.isShown()) {
            Rect sourceRect = new Rect();
            if (source.getGlobalVisibleRect(sourceRect) && !sourceRect.isEmpty()) builder.setSourceRectHint(sourceRect);
        }
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            builder.setSeamlessResizeEnabled(true);
            builder.setAutoEnterEnabled(true);
        }
        PendingIntent hangup = VoiceCallForegroundService.hangupPendingIntent(this, callId, 13004);
        RemoteAction action = new RemoteAction(Icon.createWithResource(this, R.drawable.ic_call_end), "挂断", "结束通话", hangup);
        builder.setActions(Collections.singletonList(action));
        return builder.build();
    }

    @Override public void onUserLeaveHint() {
        super.onUserLeaveHint();
        if (!terminal && callId > 0 && Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) enterCallPictureInPicture();
    }

    @Override public void onPictureInPictureModeChanged(boolean inPictureInPictureMode, @NonNull Configuration newConfig) {
        super.onPictureInPictureModeChanged(inPictureInPictureMode, newConfig);
        if (binding == null) return;
        binding.callBody.setVisibility(inPictureInPictureMode ? View.GONE : View.VISIBLE);
        binding.pipOverlay.setVisibility(inPictureInPictureMode ? View.VISIBLE : View.GONE);
        binding.pipAvatar.setVisibility(inPictureInPictureMode && !isVideoCall() ? View.VISIBLE : View.GONE);
        binding.pipDuration.setVisibility(inPictureInPictureMode && "active".equals(status)
            ? View.VISIBLE : View.GONE);
        if (!inPictureInPictureMode) {
            binding.networkHint.setVisibility(introCollapsed ? View.GONE : View.VISIBLE);
            binding.callTypeTitle.setVisibility(introCollapsed ? View.GONE : View.VISIBLE);
            binding.backButton.setVisibility(introCollapsed ? View.VISIBLE : View.GONE);
            binding.incomingControls.setVisibility("incoming".equals(direction) && "ringing".equals(status)
                ? View.VISIBLE : View.GONE);
            applyCallChromeVisibility(false);
        }
        if (isVideoCall()) {
            renderVideoCameraState();
        }
    }

    private void message(String text) {
        if (binding != null) Snackbar.make(binding.getRoot(), text == null || text.isEmpty() ? "操作失败" : text, Snackbar.LENGTH_LONG).show();
    }

    private int dp(int value) {
        return Math.round(value * getResources().getDisplayMetrics().density);
    }

    private static boolean booleanValue(JsonObject object, String key) {
        try { return object != null && object.has(key) && object.get(key).getAsBoolean(); }
        catch (RuntimeException ignored) { return false; }
    }

    @Override protected void onDestroy() {
        handler.removeCallbacksAndMessages(null);
        unregisterSystemCallMonitor();
        if (createRequest != null) createRequest.cancel();
        if (actionRequest != null) actionRequest.cancel();
        if (stateRequest != null) stateRequest.cancel();
        if (signalRequest != null) signalRequest.cancel();
        if (signalSendRequest != null) signalSendRequest.cancel();
        pendingSignals.clear();
        // Activity destruction is not a hang-up signal. Android may recreate this
        // screen while locking, unlocking, changing theme or moving in/out of PiP.
        // Only explicit user actions and a terminal server state may end the call.
        if (!isChangingConfigurations()) closeEngine();
        if (registeredReceiver) unregisterReceiver(endedReceiver);
        binding = null;
        super.onDestroy();
    }
}
