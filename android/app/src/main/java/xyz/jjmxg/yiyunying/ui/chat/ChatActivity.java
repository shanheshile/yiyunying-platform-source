package xyz.jjmxg.yiyunying.ui.chat;

import android.animation.ObjectAnimator;
import android.animation.ValueAnimator;
import android.content.Context;
import android.content.BroadcastReceiver;
import android.content.ContentUris;
import android.content.ClipData;
import android.content.ClipboardManager;
import android.content.Intent;
import android.content.IntentFilter;
import android.content.ActivityNotFoundException;
import android.database.ContentObserver;
import android.database.Cursor;
import android.net.Uri;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.provider.OpenableColumns;
import android.provider.MediaStore;
import android.content.ContentValues;
import android.content.pm.PackageManager;
import android.graphics.Bitmap;
import android.graphics.BitmapFactory;
import android.graphics.Color;
import android.media.MediaMetadataRetriever;
import android.media.MediaRecorder;
import android.speech.RecognitionListener;
import android.speech.RecognizerIntent;
import android.speech.SpeechRecognizer;
import android.util.Size;
import android.view.View;
import android.view.ViewGroup;
import android.view.MenuItem;
import android.view.MotionEvent;
import android.view.GestureDetector;
import android.view.Gravity;
import android.view.inputmethod.EditorInfo;
import android.widget.FrameLayout;
import android.widget.GridLayout;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.ScrollView;
import android.widget.TextView;
import android.text.Editable;
import android.text.TextWatcher;

import androidx.activity.result.ActivityResultLauncher;
import androidx.activity.result.ActivityResult;
import androidx.activity.result.contract.ActivityResultContracts;
import androidx.annotation.NonNull;
import androidx.appcompat.app.AppCompatActivity;
import androidx.appcompat.app.AlertDialog;
import androidx.activity.OnBackPressedCallback;
import androidx.core.content.ContextCompat;
import androidx.core.content.FileProvider;
import androidx.core.graphics.Insets;
import androidx.core.view.ViewCompat;
import androidx.core.view.WindowCompat;
import androidx.core.view.WindowInsetsCompat;
import androidx.recyclerview.widget.LinearLayoutManager;
import androidx.recyclerview.widget.RecyclerView;

import com.google.android.material.snackbar.Snackbar;
import com.bumptech.glide.Glide;
import xyz.jjmxg.yiyunying.ui.common.YiyunyingDialogBuilder;
import com.google.android.material.card.MaterialCardView;
import com.google.android.material.button.MaterialButton;
import com.google.android.material.button.MaterialButtonToggleGroup;
import com.google.android.material.bottomsheet.BottomSheetDialog;
import com.google.android.material.checkbox.MaterialCheckBox;
import com.google.android.material.chip.Chip;
import com.google.android.material.progressindicator.LinearProgressIndicator;
import com.google.android.material.textfield.TextInputEditText;
import com.google.android.material.textfield.TextInputLayout;
import com.google.gson.JsonArray;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;
import com.google.gson.JsonParser;

import java.util.ArrayList;
import java.math.BigDecimal;
import java.math.RoundingMode;
import java.util.Comparator;
import java.util.LinkedHashMap;
import java.util.LinkedHashSet;
import java.util.List;
import java.util.Map;
import java.util.Set;
import java.util.UUID;
import java.io.File;
import java.io.InputStream;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.core.AppAccess;
import xyz.jjmxg.yiyunying.core.CapturePreferences;
import xyz.jjmxg.yiyunying.core.ChatBackgroundStore;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.data.api.RequestHandle;
import xyz.jjmxg.yiyunying.databinding.ActivityChatBinding;
import xyz.jjmxg.yiyunying.databinding.BottomSheetCaptureBinding;
import xyz.jjmxg.yiyunying.domain.Role;
import xyz.jjmxg.yiyunying.domain.chat.ContactCardIdentity;
import xyz.jjmxg.yiyunying.domain.module.ActionSpec;
import xyz.jjmxg.yiyunying.domain.module.FieldSpec;
import xyz.jjmxg.yiyunying.domain.module.FieldType;
import xyz.jjmxg.yiyunying.service.MessageNotificationService;
import xyz.jjmxg.yiyunying.speech.OfflineSpeechTranscriber;
import xyz.jjmxg.yiyunying.ui.auth.LoginActivity;
import xyz.jjmxg.yiyunying.ui.common.DynamicFormDialog;
import xyz.jjmxg.yiyunying.ui.common.DisplayText;
import xyz.jjmxg.yiyunying.ui.common.GlassActionDialog;
import xyz.jjmxg.yiyunying.ui.common.GlassBottomSheet;
import xyz.jjmxg.yiyunying.ui.common.SafeTextInput;
import xyz.jjmxg.yiyunying.ui.common.ContentReportDialog;
import xyz.jjmxg.yiyunying.ui.common.ImageLoader;
import xyz.jjmxg.yiyunying.ui.common.RecordDetailDialog;
import xyz.jjmxg.yiyunying.ui.common.SecureMediaClipboard;
import xyz.jjmxg.yiyunying.ui.common.ThemeColors;
import xyz.jjmxg.yiyunying.ui.upload.ContentUriRequestBody;
import xyz.jjmxg.yiyunying.ui.upload.MediaKindDetector;
import xyz.jjmxg.yiyunying.ui.upload.MediaPickerActivity;
import xyz.jjmxg.yiyunying.ui.upload.ImageGalleryActivity;
import xyz.jjmxg.yiyunying.ui.upload.LocalMediaOptimizer;
import xyz.jjmxg.yiyunying.ui.upload.UploadPolicyStore;
import xyz.jjmxg.yiyunying.ui.profile.UserProfileActivity;
import xyz.jjmxg.yiyunying.ui.social.FavoriteCenterActivity;
import xyz.jjmxg.yiyunying.ui.document.DocumentEditorActivity;
import xyz.jjmxg.yiyunying.ui.social.SocialDirectoryActivity;
import xyz.jjmxg.yiyunying.core.RuntimeLanguage;
import xyz.jjmxg.yiyunying.ui.forum.ForumPostActivity;
import xyz.jjmxg.yiyunying.ui.location.LocationPickerActivity;
import xyz.jjmxg.yiyunying.ui.main.MainActivity;
import xyz.jjmxg.yiyunying.ui.moment.MomentTimelineActivity;
import xyz.jjmxg.yiyunying.ui.resource.ResourceHallActivity;
import xyz.jjmxg.yiyunying.ui.upload.FilePreviewActivity;
import xyz.jjmxg.yiyunying.ui.upload.FilePickerActivity;
import xyz.jjmxg.yiyunying.ui.voice.VoiceCallActivity;

@androidx.annotation.OptIn(markerClass = androidx.media3.common.util.UnstableApi.class)
public final class ChatActivity extends xyz.jjmxg.yiyunying.ui.common.SystemInsetActivity {
    private static final String EXTRA_MODE = "mode";
    private static final String EXTRA_TARGET_ID = "target_id";
    private static final String EXTRA_PEER_ID = "peer_id";
    private static final String EXTRA_TITLE = "title";
    private static final String EXTRA_FOCUS_MESSAGE_ID = "focus_message_id";
    private static final String MODE_ROOM = "room";
    private static final String MODE_CONVERSATION = "conversation";
    private static final String MODE_SERVICE_USER = "service_user";
    private static final String MODE_SERVICE_ADMIN = "service_admin";
    private static final int REQUEST_CAMERA_PERMISSION = 4202;
    private static final int REQUEST_SPEECH_PERMISSION = 4205;
    private static final int REQUEST_MEDIA_PERMISSION = 4206;
    private static final int INLINE_ALBUM_BATCH = 24;
    private static final int INLINE_ALBUM_MAX = 800;
    private static final int MAX_CONCURRENT_UPLOADS = 3;
    private static final int MAX_UPLOAD_ATTEMPTS = 2;
    private static final String STATE_INLINE_ALBUM_SELECTED = "chat.inline_album_selected";
    private static final String STATE_INLINE_ALBUM_METADATA = "chat.inline_album_metadata";
    private static final String STATE_PICKER_INITIAL_SELECTION = "chat.picker_initial_selection";
    private static final String STATE_INLINE_ALBUM_VISIBLE = "chat.inline_album_visible";
    private static final String STATE_PICKER_OPENED_FROM_INLINE = "chat.picker_opened_from_inline";
    private static final String STATE_USE_ORIGINAL_MEDIA = "chat.use_original_media";

    private static final class ViewportAnchor {
        final long messageId;
        final int fallbackPosition;
        final int offset;

        ViewportAnchor(long messageId, int fallbackPosition, int offset) {
            this.messageId = messageId;
            this.fallbackPosition = fallbackPosition;
            this.offset = offset;
        }
    }

    private static final class MessageMergeResult {
        final int added;
        final boolean changed;

        MessageMergeResult(int added, boolean changed) {
            this.added = added;
            this.changed = changed;
        }
    }

    private static final class AttachmentPreparationResult {
        final List<PendingAttachment> attachments = new ArrayList<>();
        final List<String> rejections = new ArrayList<>();
    }

    private static final class BatchUploadState {
        final String content;
        final List<PendingAttachment> attachments;
        final JsonObject[] uploadedValues;
        final int[] attempts;
        final boolean originalUpload;
        int nextIndex;
        int inFlight;
        int completed;
        boolean failed;

        BatchUploadState(String content, List<PendingAttachment> attachments, boolean originalUpload) {
            this.content = content;
            this.attachments = attachments;
            this.uploadedValues = new JsonObject[attachments.size()];
            this.attempts = new int[attachments.size()];
            this.originalUpload = originalUpload;
        }
    }

    private ActivityChatBinding binding;
    private ChatAdapter adapter;
    private LinearLayoutManager messageLayoutManager;
    private final Handler handler = new Handler(Looper.getMainLooper());
    private final Map<Long, JsonObject> messages = new LinkedHashMap<>();
    private RequestHandle pollRequest;
    private RequestHandle cachedMessageRequest;
    private RequestHandle cachedConversationResolveRequest;
    private RequestHandle conversationResolveRequest;
    private boolean messageRequestInFlight;
    private boolean messageRefreshQueued;
    private long messageRequestGeneration;
    private long conversationResolveGeneration;
    private int incrementalPollCount;
    private boolean messageRefreshReceiverRegistered;
    private RequestHandle sendRequest;
    private boolean keepKeyboardAfterSend;
    private long composerEditVersion;
    private long submittedComposerEditVersion;
    private RequestHandle policyRequest;
    private RequestHandle groupToolRequest;
    private RequestHandle roomMetadataRequest;
    private RequestHandle readRequest;
    private RequestHandle uploadRequest;
    private final List<RequestHandle> batchUploadRequests = new ArrayList<>();
    private BatchUploadState activeUploadBatch;
    private RequestHandle recallRequest;
    private RequestHandle stickerRequest;
    private RequestHandle forwardRequest;
    private RequestHandle messageActionRequest;
    private RequestHandle draftRequest;
    private RequestHandle searchHistoryRequest;
    private RequestHandle callMemberRequest;
    private RequestHandle mentionRequest;
    private long pollIntervalMs = 1000L;
    private long lastId;
    private boolean running;
    private boolean firstLoad = true;
    private int pendingNewMessageCount;
    private long viewportRestoreGeneration;
    private boolean userHoldingHistory;
    private long markedReadId;
    private long serviceSessionId;
    private long resolvedPeerId;
    private long pendingFocusMessageId;
    private final List<PendingAttachment> pendingAttachments = new ArrayList<>();
    private final Set<String> preparingAttachmentUris = new LinkedHashSet<>();
    private int attachmentPreparationCount;
    private long attachmentPreparationGeneration;
    private final Map<String, JsonObject> pendingPickerMetadata = new LinkedHashMap<>();
    private final Set<String> mediaPickerInitialSelection = new LinkedHashSet<>();
    private String pendingPickerType = "file";
    private Uri cameraUri;
    private File cameraCacheFile;
    private boolean cameraTargetInGallery;
    private Uri lastCapturedMediaUri;
    private boolean pendingVideoCapture;
    private boolean optimizeCapturedMedia = true;
    private final List<String> pendingTags = new ArrayList<>();
    private final Set<Long> pendingMentionIds = new LinkedHashSet<>();
    private boolean suppressMentionPicker;
    private final List<JsonObject> recentMedia = new ArrayList<>();
    private final List<JsonObject> inlineAlbumMedia = new ArrayList<>();
    private final Set<String> inlineAlbumSelected = new LinkedHashSet<>();
    private final Map<String, JsonObject> inlineAlbumSelectionMetadata = new LinkedHashMap<>();
    private InlineAlbumAdapter inlineAlbumAdapter;
    private int inlineAlbumDisplayCount;
    private boolean inlineAlbumLoading;
    private boolean inlineAlbumDragSelecting;
    private boolean inlineAlbumDragValue;
    private int inlineAlbumLastDragPosition = RecyclerView.NO_POSITION;
    private float inlineAlbumLastTouchX;
    private float inlineAlbumLastTouchY;
    private int inlineAlbumEdgeDirection;
    private boolean inlineAlbumEdgeScrollPosted;
    private boolean restoreInlineAlbumPanel;
    private boolean mediaPickerOpenedFromInlineAlbum;
    private final Runnable inlineAlbumEdgeScroller = new Runnable() {
        @Override public void run() {
            inlineAlbumEdgeScrollPosted = false;
            if (!inlineAlbumDragSelecting || inlineAlbumEdgeDirection == 0 || binding == null) return;
            RecyclerView recycler = binding.inlineAlbumList;
            int step = dp(12 + Math.min(12, Math.abs(inlineAlbumEdgeDirection) * 3));
            recycler.scrollBy(inlineAlbumEdgeDirection < 0 ? -step : step, 0);
            selectInlineAlbumDragPosition(recycler, inlineAlbumLastTouchX, inlineAlbumLastTouchY);
            postInlineAlbumEdgeScroll(recycler);
        }
    };
    private final ExecutorService mediaExecutor = Executors.newSingleThreadExecutor();
    private Uri recentSuggestionUri;
    private boolean recentSuggestionQueryInFlight;
    private boolean recentSuggestionRecheckPending;
    private boolean recentPhotoObserverRegistered;

    private final BroadcastReceiver messageRefreshReceiver = new BroadcastReceiver() {
        @Override public void onReceive(Context context, Intent intent) {
            if (intent == null || !MessageNotificationService.ACTION_MESSAGES_CHANGED.equals(intent.getAction())) return;
            String type = intent.getStringExtra(MessageNotificationService.EXTRA_CHANGED_TYPE);
            long targetId = intent.getLongExtra(MessageNotificationService.EXTRA_CHANGED_TARGET_ID, 0L);
            if (matchesChangedConversation(type, targetId)) refreshMessagesNow();
        }
    };
    private final Runnable recentPhotoReload = this::loadRecentSuggestion;
    private final ContentObserver recentPhotoObserver = new ContentObserver(handler) {
        @Override public void onChange(boolean selfChange, Uri uri) {
            if (!running) return;
            handler.removeCallbacks(recentPhotoReload);
            handler.postDelayed(recentPhotoReload, 500L);
        }
    };
    private boolean useOriginalMedia;
    private MediaRecorder voiceRecorder;
    private File voiceRecordingFile;
    private long voiceRecordingStartedAt;
    private float voiceTouchStartY;
    private boolean cancelVoiceRecording;
    private boolean voiceMode;
    private final Runnable voiceRecordingTicker = new Runnable() {
        @Override public void run() {
            if (voiceRecorder == null || binding == null) return;
            long elapsed = Math.min(60000L, Math.max(0L, System.currentTimeMillis() - voiceRecordingStartedAt));
            binding.voiceRecordingTime.setText(String.format(java.util.Locale.CHINA,
                "%02d:%02d / 01:00", elapsed / 60000L, (elapsed / 1000L) % 60L));
            try {
                float amplitude = voiceRecorder.getMaxAmplitude() / 32767f;
                binding.voiceRecordingWaveform.pushAmplitude((float) Math.sqrt(Math.max(0f, amplitude)));
            } catch (RuntimeException ignored) { }
            handler.postDelayed(this, 80L);
        }
    };
    private SpeechRecognizer speechEngine;
    private OfflineSpeechTranscriber offlineSpeech;
    private boolean speechListening;
    private boolean platformSpeechListening;
    private boolean offlineSpeechListening;
    private boolean speechStopRequested;
    private String speechPartialText = "";
    private ObjectAnimator speechIconAnimator;
    private static final long SPEECH_SILENCE_TIMEOUT_MS = 3000L;
    private final Runnable speechSilenceTimeout = this::stopSpeechAfterSilence;
    private boolean pendingSpeechPermission;
    private boolean pendingServerSpeechPermission;
    private MediaRecorder serverSpeechRecorder;
    private File serverSpeechFile;
    private long serverSpeechStartedAt;
    private boolean serverSpeechRecording;
    private final Runnable serverSpeechTicker = new Runnable() {
        @Override public void run() {
            if (!serverSpeechRecording || binding == null) return;
            try {
                if (serverSpeechRecorder != null && serverSpeechRecorder.getMaxAmplitude() > 900) {
                    markSpeechActivity();
                }
            } catch (RuntimeException ignored) { }
            handler.postDelayed(this, 250L);
        }
    };
    private final Set<Long> selectedMessageIds = new LinkedHashSet<>();
    private long rangeStartMessageId;
    private long rangeEndMessageId;
    private long rangeStartCandidateId;
    private long rangeEndCandidateId;
    private int selectionScrollDirection;
    private boolean selectionMode;
    private String searchQuery = "";
    private String searchContentFilter = "all";
    private JsonObject pendingQuote;
    private String normalTitle = "";
    private String roomKind = "group";
    private boolean roomMetadataResolved;
    private long roomMetadataGeneration;
    private static final int MENU_SEARCH = 7101;
    private static final int MENU_GROUP = 7102;
    private static final int MENU_CHAT_SETTINGS = 7103;
    private final Runnable saveDraftTask = this::saveDraftRemote;

    private final ActivityResultLauncher<Intent> mediaPicker = registerForActivityResult(
        new ActivityResultContracts.StartActivityForResult(), this::handleMediaPickerResult);
    private final ActivityResultLauncher<Intent> stickerUploadPicker = registerForActivityResult(
        new ActivityResultContracts.StartActivityForResult(), result -> {
            if (result.getResultCode() != RESULT_OK || result.getData() == null) return;
            ArrayList<Uri> uris = result.getData()
                .getParcelableArrayListExtra(MediaPickerActivity.EXTRA_SELECTED_URIS);
            if (uris != null && !uris.isEmpty()) uploadSticker(uris.get(0));
        });
    private final ActivityResultLauncher<Intent> favoritePicker = registerForActivityResult(
        new ActivityResultContracts.StartActivityForResult(), this::handleFavoritePickerResult);
    private final ActivityResultLauncher<Intent> visualFilePicker = registerForActivityResult(
        new ActivityResultContracts.StartActivityForResult(), this::handleFilePickerResult);
    private final ActivityResultLauncher<Intent> locationPicker = registerForActivityResult(
        new ActivityResultContracts.StartActivityForResult(), this::handleLocationPickerResult);
    private RecipientResult pendingRecipientResult;
    private final ActivityResultLauncher<Intent> recipientPicker = registerForActivityResult(
        new ActivityResultContracts.StartActivityForResult(), result -> {
            RecipientResult callback = pendingRecipientResult;
            pendingRecipientResult = null;
            if (callback == null || result.getResultCode() != RESULT_OK || result.getData() == null) return;
            String serialized = result.getData().getStringExtra(SocialDirectoryActivity.EXTRA_SELECTED_ITEMS);
            if (serialized == null || serialized.trim().isEmpty()) return;
            try {
                JsonArray values = JsonParser.parseString(serialized).getAsJsonArray();
                List<JsonObject> recipients = new ArrayList<>();
                for (JsonElement value : values) {
                    if (value.isJsonObject() && Jsons.longValue(value.getAsJsonObject(), "user_id") > 0) {
                        recipients.add(value.getAsJsonObject().deepCopy());
                    }
                }
                if (!recipients.isEmpty()) callback.onSelected(recipients);
            } catch (RuntimeException exception) {
                if (binding != null) Snackbar.make(binding.getRoot(), "好友选择结果无法读取，请重新选择", Snackbar.LENGTH_LONG).show();
            }
        });
    private final ActivityResultLauncher<Intent> speechRecognizer = registerForActivityResult(
        new ActivityResultContracts.StartActivityForResult(), this::handleSpeechResult);
    private final ActivityResultLauncher<Uri> cameraCapture = registerForActivityResult(
        new ActivityResultContracts.TakePicture(), success -> {
            if (success && cameraUri != null) {
                publishCapturedMedia(cameraUri);
                handleCapturedMedia(cameraUri, false);
            } else {
                discardCaptureTarget();
            }
            cameraUri = null;
            cameraCacheFile = null;
        });
    private final ActivityResultLauncher<Uri> videoCapture = registerForActivityResult(
        new ActivityResultContracts.CaptureVideo(), success -> {
            if (success && cameraUri != null) {
                publishCapturedMedia(cameraUri);
                handleCapturedMedia(cameraUri, true);
            } else {
                discardCaptureTarget();
            }
            cameraUri = null;
            cameraCacheFile = null;
        });

    private final Runnable poll = this::loadMessages;

    public static void openRoom(Context context, long roomId, String title) {
        context.startActivity(roomIntent(context, roomId, title));
    }

    public static void openConversation(Context context, long conversationId, long peerId, String title) {
        context.startActivity(conversationIntent(context, conversationId, peerId, title));
    }

    public static void openPeer(Context context, long peerId, String title) {
        context.startActivity(conversationIntent(context, 0, peerId, title));
    }

    public static Intent roomIntent(Context context, long roomId, String title) {
        return intent(context, MODE_ROOM, roomId, 0, title);
    }

    public static Intent conversationIntent(Context context, long conversationId, long peerId, String title) {
        return intent(context, MODE_CONVERSATION, conversationId, peerId, title);
    }

    public static void openUserService(Context context) {
        context.startActivity(userServiceIntent(context));
    }

    public static Intent userServiceIntent(Context context) {
        return intent(context, MODE_SERVICE_USER, 0, 0, "在线客服");
    }

    public static Intent focusMessage(Intent intent, long messageId) {
        return intent.putExtra(EXTRA_FOCUS_MESSAGE_ID, Math.max(0L, messageId));
    }

    public static void openAdminService(Context context, long sessionId, String title) {
        context.startActivity(intent(context, MODE_SERVICE_ADMIN, sessionId, 0, title));
    }

    private static Intent intent(Context context, String mode, long id, long peerId, String title) {
        return new Intent(context, ChatActivity.class)
            .putExtra(EXTRA_MODE, mode)
            .putExtra(EXTRA_TARGET_ID, id)
            .putExtra(EXTRA_PEER_ID, peerId)
            .putExtra(EXTRA_TITLE, title);
    }

    @Override protected void onCreate(Bundle state) {
        super.onCreate(state);
        if (!AppAccess.from(this).session().isAuthenticated()) { login(); return; }
        binding = ActivityChatBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());
        restoreComposerMediaState(state);
        SecureMediaClipboard.attachPaste(binding.messageInput, uris -> selectedUris("file", uris));
        applyChatBackground();
        resolvedPeerId = Math.max(0L, getIntent().getLongExtra(EXTRA_PEER_ID, 0L));
        pendingFocusMessageId = getIntent().getLongExtra(EXTRA_FOCUS_MESSAGE_ID, 0L);
        normalTitle = getIntent().getStringExtra(EXTRA_TITLE);
        if (normalTitle == null || normalTitle.trim().isEmpty()) normalTitle = "聊天";
        xyz.jjmxg.yiyunying.core.RuntimeLanguage.setDynamicToolbarTitle(binding.toolbar, normalTitle);
        binding.toolbar.setNavigationOnClickListener(view -> finish());
        binding.toolbar.setOnClickListener(view -> openConversationProfile());
        MenuItem search = binding.toolbar.getMenu().add(0, MENU_SEARCH, 0, "搜索聊天记录");
        search.setIcon(R.drawable.ic_search);
        search.setShowAsAction(MenuItem.SHOW_AS_ACTION_ALWAYS);
        if (MODE_ROOM.equals(mode()) && AppAccess.from(this).session().role() == Role.USER) {
            MenuItem tools = binding.toolbar.getMenu().add(0, MENU_GROUP, 1, "会话设置");
            tools.setIcon(xyz.jjmxg.yiyunying.R.drawable.ic_more);
            tools.setShowAsAction(MenuItem.SHOW_AS_ACTION_ALWAYS);
        } else if (MODE_CONVERSATION.equals(mode()) && AppAccess.from(this).session().role() == Role.USER) {
            MenuItem settings = binding.toolbar.getMenu().add(0, MENU_CHAT_SETTINGS, 1, "当前聊天设置");
            settings.setIcon(xyz.jjmxg.yiyunying.R.drawable.ic_more);
            settings.setShowAsAction(MenuItem.SHOW_AS_ACTION_ALWAYS);
        }
        binding.toolbar.setOnMenuItemClickListener(item -> {
            if (item.getItemId() == MENU_SEARCH) {
                openSearch();
                return true;
            }
            if (item.getItemId() == MENU_GROUP) {
                long roomId = getIntent().getLongExtra(EXTRA_TARGET_ID, 0L);
                if (isChatRoom()) {
                    ConversationPermissionActivity.openChatRoom(
                        this, roomId, normalTitle, conversationBackgroundIdentity());
                } else {
                    ConversationPermissionActivity.openGroup(
                        this, roomId, normalTitle, conversationBackgroundIdentity());
                }
                return true;
            }
            if (item.getItemId() == MENU_CHAT_SETTINGS) {
                long peerId = resolvedConversationPeerId();
                if (peerId <= 0L) {
                    Snackbar.make(binding.getRoot(), "正在读取对方资料，请稍后再试", Snackbar.LENGTH_SHORT).show();
                    return true;
                }
                ConversationPermissionActivity.openPrivate(
                    this,
                    getIntent().getLongExtra(EXTRA_TARGET_ID, 0L),
                    peerId,
                    normalTitle,
                    conversationBackgroundIdentity()
                );
                return true;
            }
            return false;
        });
        loadRoomMetadata();
        adapter = new ChatAdapter(AppAccess.from(this).session().actorId(), AppAccess.from(this).session().role(), new ChatAdapter.Listener() {
            @Override public void onLongPress(JsonObject message) { showMessageActions(message); }
            @Override public void onSelectionChanged(JsonObject message, boolean selected) { setMessageSelected(message, selected); }
            @Override public void onEditHistory(JsonObject message) { showEditHistory(message); }
            @Override public void onDeleteSystem(JsonObject message) { deleteSystemMessage(message); }
            @Override public void onReplyClick(long messageId) { jumpToMessage(messageId); }
            @Override public void onAvatarClick(JsonObject message) {
                long userId = Jsons.longValue(message, "sender_id");
                if (userId == 0) userId = Jsons.longValue(message, "user_id");
                UserProfileActivity.open(ChatActivity.this, userId);
            }
            @Override public void onAttachmentClick(JsonObject message, JsonObject attachment) {
                openBusinessAttachment(attachment);
            }
            @Override public void onMessageHeightWillChange() {
                preserveHistoryViewportAcrossLayoutChange();
            }
        });
        messageLayoutManager = new LinearLayoutManager(this);
        messageLayoutManager.setStackFromEnd(true);
        binding.recycler.setLayoutManager(messageLayoutManager);
        binding.recycler.setAdapter(adapter);
        binding.recycler.setHasFixedSize(true);
        binding.recycler.setItemViewCacheSize(12);
        binding.recycler.setItemAnimator(null);
        binding.recycler.addOnScrollListener(new RecyclerView.OnScrollListener() {
            @Override public void onScrolled(@NonNull RecyclerView recyclerView, int dx, int dy) {
                int state = recyclerView.getScrollState();
                if (state == RecyclerView.SCROLL_STATE_DRAGGING && dy < 0) {
                    userHoldingHistory = true;
                } else if (state != RecyclerView.SCROLL_STATE_IDLE && dy > 0 && isAtLatestMessage()) {
                    userHoldingHistory = false;
                }
                if (selectionMode && dy != 0) updateRangeAnchorsForScroll(dy);
                updateLatestMessageState();
            }

            @Override public void onScrollStateChanged(@NonNull RecyclerView recyclerView, int newState) {
                if (newState == RecyclerView.SCROLL_STATE_DRAGGING) viewportRestoreGeneration++;
                if (newState == RecyclerView.SCROLL_STATE_IDLE) {
                    updateLatestMessageState();
                    if (selectionMode) renderRangeAnchorOverlays();
                }
            }
        });
        binding.newMessageIndicator.setOnClickListener(view -> scrollToLatestMessage(true));
        binding.attachButton.setOnClickListener(view -> showFunctionPanel());
        binding.emojiButton.setOnClickListener(view -> showEmojiPanel());
        binding.voiceModeButton.setOnClickListener(view -> toggleVoiceMode());
        binding.messageInputLayout.setEndIconOnClickListener(view -> startSpeechRecognition());
        binding.messageInputLayout.setEndIconOnLongClickListener(view -> {
            startSpeechRecognition();
            return true;
        });
        prepareOfflineSpeech();
        binding.holdToTalkButton.setOnTouchListener(this::handleHoldToTalk);
        binding.sendButton.setOnClickListener(view -> send());
        configureMediaPanel();
        if (restoreInlineAlbumPanel) {
            binding.inlineAlbumPane.post(() -> {
                if (binding != null && restoreInlineAlbumPanel) {
                    restoreInlineAlbumPanel = false;
                    showInlineAlbumPanel();
                }
            });
        }
        configureSearchAndSelection();
        binding.quoteCancel.setOnClickListener(view -> clearQuote());
        restoreDraft();
        binding.messageInput.setOnEditorActionListener((view, actionId, event) -> {
            if (actionId == EditorInfo.IME_ACTION_SEND) { send(); return true; }
            return false;
        });
        binding.messageInput.setOnFocusChangeListener((view, focused) -> {
            if (focused) {
                ViewportAnchor anchor = captureHistoryViewportAnchor();
                hideMediaPanel();
                dismissRecentSuggestion();
                scheduleViewportAnchorRestore(anchor, 80L, 200L, 360L);
            }
        });
        binding.messageInput.addTextChangedListener(new TextWatcher() {
            @Override public void beforeTextChanged(CharSequence value, int start, int count, int after) { }
            @Override public void onTextChanged(CharSequence value, int start, int before, int count) {
                composerEditVersion++;
                String key = draftKey();
                String content = value == null ? "" : value.toString();
                android.content.SharedPreferences.Editor editor = getSharedPreferences("composer_drafts", 0).edit();
                if (content.trim().isEmpty()) {
                    editor.remove(key).remove("draft_time:" + key);
                } else {
                    editor.putString(key, content).putLong("draft_time:" + key, System.currentTimeMillis());
                }
                editor.apply();
                handler.removeCallbacks(saveDraftTask);
                handler.postDelayed(saveDraftTask, 700L);
                updateComposerActions();
                if (!suppressMentionPicker && count > 0 && value != null) {
                    int end = Math.min(value.length(), start + count);
                    for (int index = start; index < end; index++) {
                        if (value.charAt(index) == '@') {
                            binding.messageInput.post(ChatActivity.this::showMentionPicker);
                            break;
                        }
                    }
                }
            }
            @Override public void afterTextChanged(Editable value) { }
        });
        getOnBackPressedDispatcher().addCallback(this, new OnBackPressedCallback(true) {
            @Override public void handleOnBackPressed() {
                if (selectionMode) exitSelection();
                else if (binding != null && binding.mediaPanel.getVisibility() == View.VISIBLE) hideMediaPanel();
                else if (binding != null && binding.searchPanel.getVisibility() == View.VISIBLE) closeSearch();
                else if (pendingQuote != null) clearQuote();
                else { setEnabled(false); finish(); }
            }
        });
        updateComposerActions();
        loadPolicy();
    }

    @Override protected void onSaveInstanceState(@NonNull Bundle outState) {
        super.onSaveInstanceState(outState);
        outState.putStringArrayList(STATE_INLINE_ALBUM_SELECTED, new ArrayList<>(inlineAlbumSelected));
        outState.putStringArrayList(STATE_PICKER_INITIAL_SELECTION, new ArrayList<>(mediaPickerInitialSelection));
        outState.putBoolean(STATE_USE_ORIGINAL_MEDIA, useOriginalMedia);
        outState.putBoolean(STATE_INLINE_ALBUM_VISIBLE,
            binding != null && binding.mediaPanel.getVisibility() == View.VISIBLE
                && binding.inlineAlbumPane.getVisibility() == View.VISIBLE);
        outState.putBoolean(STATE_PICKER_OPENED_FROM_INLINE, mediaPickerOpenedFromInlineAlbum);
        JsonObject metadata = new JsonObject();
        for (Map.Entry<String, JsonObject> entry : inlineAlbumSelectionMetadata.entrySet()) {
            if (entry.getValue() != null) metadata.add(entry.getKey(), entry.getValue().deepCopy());
        }
        outState.putString(STATE_INLINE_ALBUM_METADATA, metadata.toString());
    }

    private void restoreComposerMediaState(Bundle state) {
        if (state == null) return;
        ArrayList<String> selectedValues = state.getStringArrayList(STATE_INLINE_ALBUM_SELECTED);
        if (selectedValues != null) inlineAlbumSelected.addAll(selectedValues);
        ArrayList<String> initialValues = state.getStringArrayList(STATE_PICKER_INITIAL_SELECTION);
        if (initialValues != null) mediaPickerInitialSelection.addAll(initialValues);
        useOriginalMedia = state.getBoolean(STATE_USE_ORIGINAL_MEDIA, false);
        restoreInlineAlbumPanel = state.getBoolean(STATE_INLINE_ALBUM_VISIBLE, false);
        mediaPickerOpenedFromInlineAlbum = state.getBoolean(STATE_PICKER_OPENED_FROM_INLINE, false);
        try {
            String raw = state.getString(STATE_INLINE_ALBUM_METADATA, "{}");
            JsonObject metadata = JsonParser.parseString(raw).getAsJsonObject();
            for (Map.Entry<String, JsonElement> entry : metadata.entrySet()) {
                if (entry.getValue().isJsonObject() && inlineAlbumSelected.contains(entry.getKey())) {
                    inlineAlbumSelectionMetadata.put(entry.getKey(), entry.getValue().getAsJsonObject().deepCopy());
                }
            }
        } catch (RuntimeException ignored) { }
    }

    private void applyChatBackground() {
        if (binding == null || isFinishing() || isDestroyed()) return;
        Glide.with(getApplicationContext()).clear(binding.chatBackground);
        String value = ChatBackgroundStore.resolved(this, conversationBackgroundIdentity());
        if (value == null || value.trim().isEmpty()) {
            binding.chatBackground.setImageDrawable(null);
            binding.chatBackground.setVisibility(View.GONE);
            return;
        }
        Uri uri;
        try {
            uri = Uri.parse(value);
        } catch (RuntimeException ignored) {
            binding.chatBackground.setVisibility(View.GONE);
            return;
        }
        binding.chatBackground.setVisibility(View.VISIBLE);
        Glide.with(getApplicationContext())
            .load(uri)
            .centerCrop()
            .into(binding.chatBackground);
    }

    private String conversationBackgroundIdentity() {
        if (MODE_ROOM.equals(mode())) {
            long roomId = getIntent().getLongExtra(EXTRA_TARGET_ID, 0L);
            return roomId > 0L ? (isChatRoom() ? "chat_room:" : "group_room:") + roomId : "";
        }
        if (!MODE_CONVERSATION.equals(mode())) return "";
        long peerId = resolvedConversationPeerId();
        if (peerId > 0L) return "private_peer:" + peerId;
        long conversationId = getIntent().getLongExtra(EXTRA_TARGET_ID, 0L);
        return conversationId > 0L ? "private_conversation:" + conversationId : "";
    }

    private void loadRoomMetadata() {
        if (!MODE_ROOM.equals(mode())) return;
        long roomId = getIntent().getLongExtra(EXTRA_TARGET_ID, 0L);
        if (roomId <= 0L) return;
        if (roomMetadataRequest != null) roomMetadataRequest.cancel();
        long generation = ++roomMetadataGeneration;
        roomMetadataRequest = AppAccess.from(this).repository().get(
            "/api/user/chat-rooms/" + roomId,
            new LinkedHashMap<>(),
            result -> {
                if (generation != roomMetadataGeneration) return;
                roomMetadataRequest = null;
                if (binding == null || isFinishing() || isDestroyed() || !result.isSuccessful()) return;
                JsonObject room = Jsons.object(result.dataObject(), "room");
                roomKind = "chat_room".equals(Jsons.string(room, "room_kind")) ? "chat_room" : "group";
                roomMetadataResolved = true;
                String title = Jsons.string(room, "name");
                if (title != null && !title.trim().isEmpty()) {
                    normalTitle = title.trim();
                    xyz.jjmxg.yiyunying.core.RuntimeLanguage.setDynamicToolbarTitle(binding.toolbar, normalTitle);
                }
                refreshRoomPresentation();
                applyChatBackground();
            }
        );
    }

    private boolean isChatRoom() {
        return "chat_room".equals(roomKind);
    }

    private String roomEntityLabel() {
        if (!MODE_ROOM.equals(mode()) || !roomMetadataResolved) return "会话";
        return isChatRoom() ? "聊天室" : "群聊";
    }

    private String memberEntityLabel() {
        if (!MODE_ROOM.equals(mode()) || !roomMetadataResolved) return "成员";
        return isChatRoom() ? "聊天室成员" : "群成员";
    }

    private void refreshRoomPresentation() {
        if (binding == null) return;
        if (!MODE_ROOM.equals(mode())) {
            binding.functionPaneTitle.setText("私聊功能");
            binding.voiceCallLabel.setText("语音通话");
            binding.videoCallLabel.setText("视频通话");
            return;
        }
        String entity = roomEntityLabel();
        binding.functionPaneTitle.setText(entity + "功能");
        binding.voiceCallLabel.setText(roomMetadataResolved
            ? (isChatRoom() ? "聊天室语音" : "群内语音") : "语音通话");
        binding.videoCallLabel.setText(roomMetadataResolved
            ? (isChatRoom() ? "聊天室视频" : "群内视频") : "视频通话");
        MenuItem menu = binding.toolbar.getMenu().findItem(MENU_GROUP);
        if (menu != null) menu.setTitle(entity + "设置");
    }

    private void openConversationProfile() {
        if (MODE_CONVERSATION.equals(mode())) {
            long peerId = resolvedConversationPeerId();
            if (peerId > 0L) {
                UserProfileActivity.open(this, peerId);
            } else {
                Snackbar.make(binding.getRoot(), "正在读取对方资料，请稍后再试", Snackbar.LENGTH_SHORT).show();
            }
        } else if (MODE_ROOM.equals(mode())) {
            GroupSpaceActivity.open(this, getIntent().getLongExtra(EXTRA_TARGET_ID, 0L), normalTitle);
        }
    }

    private void configureSearchAndSelection() {
        binding.searchClose.setOnClickListener(view -> closeSearch());
        binding.searchClearHistory.setOnClickListener(view -> clearSearchHistory());
        binding.searchInput.setOnEditorActionListener((view, actionId, event) -> {
            if (actionId == EditorInfo.IME_ACTION_SEARCH) {
                executeSearch(view.getText() == null ? "" : view.getText().toString());
                return true;
            }
            return false;
        });
        bindSearchFilter(binding.searchAll, "all");
        bindSearchFilter(binding.searchImages, "image");
        bindSearchFilter(binding.searchVideos, "video");
        bindSearchFilter(binding.searchAudio, "audio");
        bindSearchFilter(binding.searchFiles, "file");
        bindSearchFilter(binding.searchStickers, "sticker");
        bindSearchFilter(binding.searchTags, "tag");
        bindSearchFilter(binding.searchLinks, "link");
        bindSearchFilter(binding.searchFavorites, "favorite");
        bindSearchFilter(binding.searchTransfers, "transfer");
        bindSearchFilter(binding.searchRedPackets, "red_packet");
        bindSearchFilter(binding.searchLocations, "location");
        binding.selectionForward.setOnClickListener(view -> {
            List<Long> ids = selectedIds();
            if (!ids.isEmpty()) chooseForwardTarget(ids);
        });
        binding.selectionFavorite.setOnClickListener(view -> applySelectionAction("favorite"));
        binding.selectionDelete.setOnClickListener(view -> confirmLocalDelete());
        binding.selectionStart.setOnClickListener(view -> setRangeStart());
        binding.selectionEnd.setOnClickListener(view -> selectRangeToCurrent());
        binding.rangeStartAnchor.setOnClickListener(view -> commitRangeStartCandidate());
        binding.rangeEndAnchor.setOnClickListener(view -> commitRangeEndCandidate());
        binding.selectionMore.setOnClickListener(view -> showSelectedMessageActions());
        binding.selectionCancel.setOnClickListener(view -> exitSelection());
    }

    private void bindSearchFilter(Chip chip, String filter) {
        chip.setOnClickListener(view -> {
            searchContentFilter = filter;
            String keyword = binding.searchInput.getText() == null ? "" : binding.searchInput.getText().toString();
            executeSearch(keyword);
        });
    }

    private void openSearch() {
        if (selectionMode) exitSelection();
        binding.searchPanel.setVisibility(View.VISIBLE);
        loadSearchHistory();
        binding.searchInput.requestFocus();
        binding.searchInput.post(() -> {
            android.view.inputmethod.InputMethodManager keyboard = (android.view.inputmethod.InputMethodManager) getSystemService(Context.INPUT_METHOD_SERVICE);
            keyboard.showSoftInput(binding.searchInput, android.view.inputmethod.InputMethodManager.SHOW_IMPLICIT);
        });
    }

    private void closeSearch() {
        binding.searchPanel.setVisibility(View.GONE);
        binding.searchInput.setText("");
        binding.searchHistoryContainer.removeAllViews();
        binding.searchSummary.setText("搜索结果会同时保留命中消息前后各 3 条上下文");
        if (searchHistoryRequest != null) {
            searchHistoryRequest.cancel();
            searchHistoryRequest = null;
        }
        boolean hadSearch = !searchQuery.isEmpty() || !"all".equals(searchContentFilter);
        searchContentFilter = "all";
        binding.searchAll.setChecked(true);
        if (hadSearch) {
            searchQuery = "";
            reloadFromStart();
        }
    }

    private void executeSearch(String keyword) {
        searchQuery = keyword == null ? "" : keyword.trim();
        if (searchQuery.isEmpty() && "all".equals(searchContentFilter)) {
            binding.searchSummary.setText("请输入要查找的聊天内容、发送者或上下文");
            return;
        }
        binding.searchInput.setText(searchQuery);
        binding.searchInput.setSelection(binding.searchInput.length());
        reloadFromStart();
    }

    private void loadSearchHistory() {
        binding.searchHistoryContainer.removeAllViews();
        if (AppAccess.from(this).session().role() != Role.USER || MODE_SERVICE_ADMIN.equals(mode())) {
            binding.searchClearHistory.setVisibility(View.GONE);
            return;
        }
        binding.searchClearHistory.setVisibility(View.VISIBLE);
        if (searchHistoryRequest != null) searchHistoryRequest.cancel();
        Map<String, String> query = new LinkedHashMap<>();
        query.put("scope_type", searchScope());
        long targetId = searchTargetId();
        if (targetId > 0) query.put("target_id", String.valueOf(targetId));
        searchHistoryRequest = AppAccess.from(this).repository().get("/api/user/chat-search/history", query, result -> {
            searchHistoryRequest = null;
            if (binding == null) return;
            binding.searchHistoryContainer.removeAllViews();
            for (JsonObject item : result.objectItems()) {
                String keyword = Jsons.string(item, "keyword");
                if (keyword.isEmpty()) continue;
                Chip chip = new Chip(this);
                chip.setText(keyword);
                chip.setCheckable(false);
                chip.setOnClickListener(view -> executeSearch(keyword));
                binding.searchHistoryContainer.addView(chip);
            }
            if (binding.searchHistoryContainer.getChildCount() == 0) {
                TextView empty = new TextView(this);
                empty.setText("暂无搜索历史");
                empty.setTextColor(getColor(R.color.on_surface_variant));
                empty.setPadding(dp(8), 0, dp(8), 0);
                binding.searchHistoryContainer.addView(empty);
            }
        });
    }

    private void clearSearchHistory() {
        if (AppAccess.from(this).session().role() != Role.USER || searchHistoryRequest != null) return;
        JsonObject body = new JsonObject();
        body.addProperty("scope_type", searchScope());
        long targetId = searchTargetId();
        if (targetId > 0) body.addProperty("target_id", targetId);
        searchHistoryRequest = AppAccess.from(this).repository().delete("/api/user/chat-search/history", body, result -> {
            searchHistoryRequest = null;
            if (binding == null) return;
            Snackbar.make(binding.getRoot(), result.isSuccessful() ? "当前会话的搜索历史已清空"
                : (result.message().isEmpty() ? "搜索历史清理失败" : result.message()), Snackbar.LENGTH_LONG).show();
            if (result.isSuccessful()) loadSearchHistory();
        });
    }

    private void reloadFromStart() {
        invalidateMessageRequest();
        messages.clear();
        adapter.submit(new ArrayList<>());
        lastId = 0;
        incrementalPollCount = 0;
        pendingNewMessageCount = 0;
        renderNewMessageIndicator();
        firstLoad = true;
        loadMessages();
    }

    private boolean adoptCreatedConversation(JsonObject data) {
        if (!MODE_CONVERSATION.equals(mode()) || getIntent().getLongExtra(EXTRA_TARGET_ID, 0) > 0) {
            return false;
        }
        long conversationId = Jsons.longValue(data, "conversation_id");
        if (conversationId <= 0) return false;
        invalidateMessageRequest();
        getIntent().putExtra(EXTRA_TARGET_ID, conversationId);
        messages.clear();
        adapter.submit(new ArrayList<>());
        lastId = 0;
        incrementalPollCount = 0;
        pendingNewMessageCount = 0;
        firstLoad = true;
        binding.emptyText.setVisibility(View.GONE);
        return true;
    }

    private void enterSelection(JsonObject message) {
        if (!isRangeSelectable(message)) return;
        long id = Jsons.longValue(message, "id");
        if (id <= 0) return;
        selectionMode = true;
        selectedMessageIds.clear();
        selectedMessageIds.add(id);
        rangeStartMessageId = id;
        rangeEndMessageId = id;
        rangeStartCandidateId = id;
        rangeEndCandidateId = id;
        selectionScrollDirection = 0;
        renderSelection();
    }

    private void setMessageSelected(JsonObject message, boolean selected) {
        long id = Jsons.longValue(message, "id");
        if (id <= 0 || !isRangeSelectable(message)) return;
        selectionMode = true;
        if (selected) selectedMessageIds.add(id); else selectedMessageIds.remove(id);
        if (selectedMessageIds.isEmpty()) {
            exitSelection();
            return;
        }
        refreshRangeBoundsFromSelection();
        selectionScrollDirection = 0;
        renderSelection();
    }

    private void renderSelection() {
        ViewportAnchor anchor = captureHistoryViewportAnchor();
        binding.selectionBar.setVisibility(View.VISIBLE);
        binding.composer.setVisibility(View.GONE);
        binding.pendingScroll.setVisibility(View.GONE);
        binding.toolbar.setTitle("已选择 " + selectedMessageIds.size() + " 条消息");
        adapter.setSelectionMode(true, selectedMessageIds);
        scheduleViewportAnchorRestore(anchor, 0L, 80L, 180L);
        binding.recycler.post(this::renderRangeAnchorOverlays);
    }

    private void exitSelection() {
        selectionMode = false;
        selectedMessageIds.clear();
        rangeStartMessageId = 0;
        rangeEndMessageId = 0;
        rangeStartCandidateId = 0;
        rangeEndCandidateId = 0;
        selectionScrollDirection = 0;
        binding.rangeStartOverlay.setVisibility(View.GONE);
        binding.rangeEndOverlay.setVisibility(View.GONE);
        binding.selectionBar.setVisibility(View.GONE);
        binding.composer.setVisibility(View.VISIBLE);
        xyz.jjmxg.yiyunying.core.RuntimeLanguage.setDynamicToolbarTitle(binding.toolbar, normalTitle);
        renderPendingAttachments();
        adapter.setSelectionMode(false, selectedMessageIds);
    }

    private List<Long> selectedIds() { return new ArrayList<>(selectedMessageIds); }

    private JsonObject selectedMessage() {
        if (selectedMessageIds.size() != 1) return null;
        return messageById(selectedMessageIds.iterator().next());
    }

    private JsonObject messageById(long messageId) {
        JsonObject direct = messages.get(messageId);
        if (direct != null) return direct;
        for (JsonObject item : adapter.messages()) {
            if (Jsons.longValue(item, "id") == messageId) return item;
        }
        return null;
    }

    private boolean isRangeSelectable(JsonObject message) {
        if (message == null) return false;
        String contentType = Jsons.string(message, "content_type");
        return !"recall".equals(contentType)
            && !boolValue(message, "is_recalled")
            && !"system".equals(Jsons.string(message, "sender_type"));
    }

    private void refreshRangeBoundsFromSelection() {
        int first = Integer.MAX_VALUE;
        int last = RecyclerView.NO_POSITION;
        long firstId = 0L;
        long lastIdValue = 0L;
        for (Long id : selectedMessageIds) {
            int position = adapter.positionOf(id);
            if (position >= 0 && position < first) {
                first = position;
                firstId = id;
            }
            if (position >= 0 && position > last) {
                last = position;
                lastIdValue = id;
            }
        }
        rangeStartMessageId = firstId;
        rangeEndMessageId = lastIdValue > 0 ? lastIdValue : firstId;
        rangeStartCandidateId = rangeStartMessageId;
        rangeEndCandidateId = rangeEndMessageId;
    }

    private void setRangeStart() {
        JsonObject selected = selectedMessage();
        if (selected == null) {
            Snackbar.make(binding.getRoot(), "请先只选择一条消息作为起点", Snackbar.LENGTH_LONG).show();
            return;
        }
        setRangeStart(selected);
    }

    private void setRangeStart(JsonObject message) {
        long id = Jsons.longValue(message, "id");
        if (id <= 0 || !isRangeSelectable(message)) return;
        selectionMode = true;
        rangeStartMessageId = id;
        rangeEndMessageId = id;
        rangeStartCandidateId = id;
        rangeEndCandidateId = id;
        selectionScrollDirection = 0;
        selectedMessageIds.clear();
        selectedMessageIds.add(id);
        renderSelection();
    }

    private void selectRangeTo(JsonObject message) {
        long endId = Jsons.longValue(message, "id");
        if (endId <= 0 || !isRangeSelectable(message)) return;
        if (rangeStartMessageId <= 0) {
            rangeStartMessageId = firstVisibleSelectableMessageId();
        }
        int start = adapter.positionOf(rangeStartMessageId);
        int end = adapter.positionOf(endId);
        if (start < 0 || end < 0) return;
        List<JsonObject> visible = adapter.messages();
        selectedMessageIds.clear();
        for (int index = Math.min(start, end); index <= Math.max(start, end); index++) {
            JsonObject item = visible.get(index);
            if (!isRangeSelectable(item)) continue;
            long id = Jsons.longValue(item, "id");
            if (id > 0) selectedMessageIds.add(id);
        }
        rangeEndMessageId = endId;
        rangeStartCandidateId = rangeStartMessageId;
        rangeEndCandidateId = rangeEndMessageId;
        selectionScrollDirection = 0;
        renderSelection();
    }

    private void selectRangeToCurrent() {
        long candidate = rangeEndCandidateId > 0 ? rangeEndCandidateId : lastVisibleSelectableMessageId();
        JsonObject message = messageById(candidate);
        if (message == null) {
            Snackbar.make(binding.getRoot(), "请滑动到终点消息后再试", Snackbar.LENGTH_LONG).show();
            return;
        }
        selectRangeTo(message);
    }

    private void updateRangeAnchorsForScroll(int dy) {
        if (!selectionMode || adapter == null || adapter.getItemCount() == 0) return;
        if (dy < 0) {
            long candidate = firstVisibleSelectableMessageId();
            if (candidate > 0) rangeStartCandidateId = candidate;
            selectionScrollDirection = -1;
        } else if (dy > 0) {
            long candidate = lastVisibleSelectableMessageId();
            if (candidate > 0) rangeEndCandidateId = candidate;
            selectionScrollDirection = 1;
        }
        renderRangeAnchorOverlays();
    }

    private void commitRangeStartCandidate() {
        long candidate = rangeStartCandidateId > 0 ? rangeStartCandidateId : firstVisibleSelectableMessageId();
        JsonObject message = messageById(candidate);
        if (message != null) setRangeStart(message);
    }

    private void commitRangeEndCandidate() {
        long candidate = rangeEndCandidateId > 0 ? rangeEndCandidateId : lastVisibleSelectableMessageId();
        JsonObject message = messageById(candidate);
        if (message != null) selectRangeTo(message);
    }

    private long firstVisibleSelectableMessageId() {
        if (messageLayoutManager == null || adapter == null) return 0L;
        int first = messageLayoutManager.findFirstVisibleItemPosition();
        int last = messageLayoutManager.findLastVisibleItemPosition();
        return selectableMessageIdBetween(first, last, 1);
    }

    private long lastVisibleSelectableMessageId() {
        if (messageLayoutManager == null || adapter == null) return 0L;
        int first = messageLayoutManager.findFirstVisibleItemPosition();
        int last = messageLayoutManager.findLastVisibleItemPosition();
        return selectableMessageIdBetween(last, first, -1);
    }

    private long selectableMessageIdBetween(int from, int to, int step) {
        List<JsonObject> visible = adapter.messages();
        if (visible.isEmpty() || from == RecyclerView.NO_POSITION || to == RecyclerView.NO_POSITION) return 0L;
        int index = Math.max(0, Math.min(from, visible.size() - 1));
        int boundary = Math.max(0, Math.min(to, visible.size() - 1));
        while ((step > 0 && index <= boundary) || (step < 0 && index >= boundary)) {
            JsonObject message = visible.get(index);
            if (isRangeSelectable(message)) return Jsons.longValue(message, "id");
            index += step;
        }
        return 0L;
    }

    private void renderRangeAnchorOverlays() {
        if (binding == null || !selectionMode) return;
        long startId = rangeStartCandidateId > 0 ? rangeStartCandidateId : rangeStartMessageId;
        long endId = rangeEndCandidateId > 0 ? rangeEndCandidateId : rangeEndMessageId;
        boolean showStart = selectionScrollDirection <= 0;
        boolean showEnd = selectionScrollDirection >= 0;
        positionRangeOverlay(binding.rangeStartOverlay, startId, true, showStart);
        positionRangeOverlay(binding.rangeEndOverlay, endId, false, showEnd);
    }

    private void positionRangeOverlay(View overlay, long messageId, boolean above, boolean show) {
        if (!show || messageId <= 0 || messageLayoutManager == null) {
            overlay.setVisibility(View.GONE);
            return;
        }
        int position = adapter.positionOf(messageId);
        View messageView = position < 0 ? null : messageLayoutManager.findViewByPosition(position);
        if (messageView == null) {
            overlay.setVisibility(View.GONE);
            return;
        }
        int overlayHeight = overlay.getHeight() > 0 ? overlay.getHeight() : dp(42);
        float messageEdge = binding.recycler.getTop() + (above ? messageView.getTop() : messageView.getBottom());
        float desiredTop = messageEdge - (overlayHeight / 2f);
        float maxTop = Math.max(0f, binding.messageStage.getHeight() - overlayHeight);
        overlay.setTranslationY(Math.max(0f, Math.min(desiredTop, maxTop)));
        overlay.setVisibility(View.VISIBLE);
        overlay.bringToFront();
    }

    private void showSelectedMessageActions() {
        JsonObject message = selectedMessage();
        if (message == null) {
            Snackbar.make(binding.getRoot(), "更多操作一次只支持一条消息", Snackbar.LENGTH_LONG).show();
            return;
        }
        List<GlassActionDialog.Action> actions = new ArrayList<>();
        String text = messageCopyText(message);
        if (!text.isEmpty()) actions.add(new GlassActionDialog.Action("复制", R.drawable.ic_content_paste, () -> copyMessage(text)));
        if (canRecall(message)) actions.add(new GlassActionDialog.Action("撤回", R.drawable.ic_refresh, () -> confirmRecall(message)));
        if (actions.isEmpty()) return;
        GlassActionDialog.showCompact(this, actions);
    }

    private void confirmLocalDelete() {
        if (selectedMessageIds.isEmpty()) return;
        new YiyunyingDialogBuilder(this).setTitle("从当前账号删除")
            .setMessage("只删除当前账号看到的本地显示，不会删除云端聊天记录，也不等于撤回消息。")
            .setPositiveButton("确认删除", (dialog, which) -> applySelectionAction("delete"))
            .setNegativeButton("取消", null).show();
    }

    private void applySelectionAction(String action) {
        if (AppAccess.from(this).session().role() != Role.USER || selectedMessageIds.isEmpty()) {
            Snackbar.make(binding.getRoot(), "当前身份不能执行此操作", Snackbar.LENGTH_LONG).show();
            return;
        }
        List<Long> ids = selectedIds();
        applySelectionAction(ids, 0, action, 0);
    }

    private void applySelectionAction(List<Long> ids, int index, String action, int success) {
        if (index >= ids.size()) {
            Snackbar.make(binding.getRoot(), "已处理 " + success + " 条消息", Snackbar.LENGTH_LONG).show();
            ViewportAnchor anchor = captureHistoryViewportAnchor();
            if ("delete".equals(action)) for (Long id : ids) messages.remove(id);
            adapter.submit(orderedMessageSnapshot());
            scheduleViewportAnchorRestore(anchor, 0L, 80L, 180L);
            exitSelection();
            return;
        }
        long id = ids.get(index);
        String path;
        if (MODE_CONVERSATION.equals(mode())) path = "/api/user/messages/" + id + "/state";
        else {
            String scope = MODE_ROOM.equals(mode()) ? "group" : "service";
            long target = MODE_ROOM.equals(mode()) ? getIntent().getLongExtra(EXTRA_TARGET_ID, 0) : serviceSessionId;
            path = "/api/user/communication/" + scope + "/" + target + "/messages/" + id + "/state";
        }
        JsonObject body = new JsonObject(); body.addProperty("action", action);
        AppAccess.from(this).repository().post(path, body, result ->
            applySelectionAction(ids, index + 1, action, success + (result.isSuccessful() ? 1 : 0)));
    }

    private void restoreDraft() {
        String local = getSharedPreferences("composer_drafts", 0).getString(draftKey(), "");
        if (local != null && !local.isEmpty()) binding.messageInput.setText(local);
    }

    private String draftKey() {
        return mode() + ":" + getIntent().getLongExtra(EXTRA_TARGET_ID, 0) + ":" + resolvedConversationPeerId();
    }

    private String draftPath() {
        String type = MODE_ROOM.equals(mode()) ? "group" : (MODE_CONVERSATION.equals(mode()) ? "private" : "service");
        long id = MODE_SERVICE_USER.equals(mode()) ? Math.max(0, serviceSessionId) : getIntent().getLongExtra(EXTRA_TARGET_ID, 0);
        return "/api/user/drafts/" + type + "/" + id;
    }

    private void saveDraftRemote() {
        if (binding == null || AppAccess.from(this).session().role() != Role.USER) return;
        JsonObject body = new JsonObject();
        body.addProperty("content", binding.messageInput.getText() == null ? "" : binding.messageInput.getText().toString());
        JsonArray tags = new JsonArray(); for (String tag : pendingTags) tags.add(tag); body.add("tags", tags);
        body.add("attachments", new JsonArray());
        if (draftRequest != null) draftRequest.cancel();
        draftRequest = AppAccess.from(this).repository().put(draftPath(), body, result -> draftRequest = null);
    }

    private void configureMediaPanel() {
        bindAction(binding.albumAction, view -> showInlineAlbumPanel());
        bindAction(binding.cameraAction, view -> showCaptureOptions());
        bindAction(binding.fileAction, view -> openCommonDocumentPicker());
        bindAction(binding.favoriteAction, view -> openFavoritePicker());
        bindAction(binding.redPacketAction, view -> createChatRedPacket());
        bindAction(binding.transferAction, view -> createChatTransfer());
        bindAction(binding.contactCardAction, view -> chooseContactCard());
        bindAction(binding.giftAction, view -> chooseChatGift());
        bindAction(binding.voiceCallAction, view -> startNetworkCall(false));
        bindAction(binding.videoCallAction, view -> startNetworkCall(true));
        bindAction(binding.locationAction, view -> locationPicker.launch(LocationPickerActivity.pickerIntent(this)));
        refreshRoomPresentation();
        binding.functionPager.setPageCount(2);
        binding.functionPager.addOnLayoutChangeListener((view, left, top, right, bottom,
            oldLeft, oldTop, oldRight, oldBottom) -> configureFunctionPageWidths(false));
        binding.functionPager.post(() -> configureFunctionPageWidths(false));
        centerFunctionIcons(binding.functionGrid);
        centerFunctionIcons(binding.functionGridSecond);
        binding.functionPager.setOnScrollChangeListener((view, scrollX, scrollY, oldScrollX, oldScrollY) -> {
            int pageWidth = Math.max(1, binding.functionPager.getWidth());
            binding.functionPageIndicator.setText(scrollX >= pageWidth / 2 ? "2 / 2" : "1 / 2");
            float progress = Math.max(0f, Math.min(1f, scrollX / (float) pageWidth));
            binding.functionGrid.setAlpha(1f - (0.10f * progress));
            binding.functionGridSecond.setAlpha(0.90f + (0.10f * progress));
        });
        binding.showStickersButton.setOnClickListener(view -> showStickerPanel());
        binding.showEmojiButton.setOnClickListener(view -> showEmojiPanel());
        binding.openStickersButton.setOnClickListener(view -> loadStickers());
        binding.uploadStickerButton.setOnClickListener(view ->
            stickerUploadPicker.launch(MediaPickerActivity.imageIntent(this, 1)));
        configureInlineAlbum();
        String[] emojis = xyz.jjmxg.yiyunying.ui.common.EmojiCatalog.values();
        for (String emoji : emojis) {
            TextView item = new TextView(this);
            item.setText(emoji); item.setTextSize(24); item.setGravity(android.view.Gravity.CENTER);
            GridLayout.LayoutParams params = new GridLayout.LayoutParams();
            params.width = 0; params.height = dp(48); params.columnSpec = GridLayout.spec(GridLayout.UNDEFINED, 1f);
            item.setLayoutParams(params);
            item.setOnClickListener(view -> {
                int start = Math.max(0, binding.messageInput.getSelectionStart());
                binding.messageInput.getText().insert(start, emoji);
            });
            binding.emojiGrid.addView(item);
        }
    }

    private void startNetworkCall(boolean video) {
        if (AppAccess.from(this).session().role() != Role.USER) {
            Snackbar.make(binding.getRoot(), "当前身份不能发起用户网络通话", Snackbar.LENGTH_LONG).show();
            return;
        }
        if (MODE_ROOM.equals(mode())) {
            chooseRoomCallMember(video);
            return;
        }
        if (!MODE_CONVERSATION.equals(mode())) {
            Snackbar.make(binding.getRoot(), "请在好友私聊、群聊或聊天室中发起网络通话", Snackbar.LENGTH_LONG).show();
            return;
        }
        long peerId = resolvedConversationPeerId();
        if (peerId <= 0) {
            Snackbar.make(binding.getRoot(), "无法识别通话对象，请返回消息列表后重试", Snackbar.LENGTH_LONG).show();
            return;
        }
        String peerName = getIntent().getStringExtra(EXTRA_TITLE);
        if (peerName == null || peerName.trim().isEmpty()) peerName = "好友";
        try {
            VoiceCallActivity.startOutgoing(this, peerId, peerName, "", video);
        } catch (RuntimeException error) {
            Snackbar.make(binding.getRoot(), "无法打开通话页面，请稍后重试", Snackbar.LENGTH_LONG).show();
        }
    }

    private void chooseRoomCallMember(boolean video) {
        if (callMemberRequest != null) return;
        long roomId = getIntent().getLongExtra(EXTRA_TARGET_ID, 0);
        if (roomId <= 0) {
            Snackbar.make(binding.getRoot(), "无法识别当前" + roomEntityLabel(), Snackbar.LENGTH_LONG).show();
            return;
        }
        LinkedHashMap<String, String> query = new LinkedHashMap<>();
        query.put("page", "1");
        query.put("limit", "200");
        binding.progress.setVisibility(View.VISIBLE);
        callMemberRequest = AppAccess.from(this).repository().get(
            "/api/user/chat-rooms/" + roomId + "/members", query, result -> {
                callMemberRequest = null;
                if (binding == null) return;
                binding.progress.setVisibility(View.GONE);
                if (!result.isSuccessful()) {
                    Snackbar.make(binding.getRoot(), result.message().isEmpty()
                        ? memberEntityLabel() + "加载失败" : result.message(), Snackbar.LENGTH_LONG).show();
                    return;
                }
                JsonArray source = Jsons.array(result.dataObject(), "items");
                List<JsonObject> members = new ArrayList<>();
                List<String> labels = new ArrayList<>();
                long selfId = AppAccess.from(this).session().actorId();
                for (JsonElement element : source) {
                    if (!element.isJsonObject()) continue;
                    JsonObject member = element.getAsJsonObject();
                    if (Jsons.longValue(member, "user_id") == selfId) continue;
                    String name = Jsons.string(member, "nickname");
                    if (name.isEmpty()) name = Jsons.string(member, "account");
                    members.add(member);
                    labels.add(name + "  ·  UID " + Jsons.string(member, "uid"));
                }
                if (members.isEmpty()) {
                    Snackbar.make(binding.getRoot(), "当前" + roomEntityLabel() + "没有其他可通话成员", Snackbar.LENGTH_LONG).show();
                    return;
                }
                new YiyunyingDialogBuilder(this)
                    .setTitle(video ? "选择视频通话成员" : "选择语音通话成员")
                    .setItems(labels.toArray(new String[0]), (dialog, which) -> {
                        JsonObject member = members.get(which);
                        String name = Jsons.string(member, "nickname");
                        if (name.isEmpty()) name = Jsons.string(member, "account");
                        VoiceCallActivity.startOutgoing(this, Jsons.longValue(member, "user_id"), name,
                            Jsons.string(member, "avatar"), video, "room", roomId,
                            getIntent().getStringExtra(EXTRA_TITLE));
                    })
                    .setNegativeButton("取消", null)
                    .show();
            });
    }

    private void configureFunctionPageWidths(boolean reset) {
        if (binding == null) return;
        int pageWidth = binding.functionPager.getWidth();
        if (pageWidth <= 0) return;
        ViewGroup.LayoutParams first = binding.functionGrid.getLayoutParams();
        first.width = pageWidth;
        binding.functionGrid.setLayoutParams(first);
        ViewGroup.LayoutParams second = binding.functionGridSecond.getLayoutParams();
        second.width = pageWidth;
        binding.functionGridSecond.setLayoutParams(second);
        ViewGroup.LayoutParams pages = binding.functionPages.getLayoutParams();
        pages.width = pageWidth * 2;
        binding.functionPages.setLayoutParams(pages);
        if (reset) {
            binding.functionPager.scrollTo(0, 0);
            binding.functionPageIndicator.setText("1 / 2");
        }
    }

    private void centerFunctionIcons(View view) {
        if (view instanceof MaterialButton) {
            MaterialButton button = (MaterialButton) view;
            int size = dp(56);
            ViewGroup.LayoutParams layout = button.getLayoutParams();
            layout.width = size;
            layout.height = size;
            button.setLayoutParams(layout);
            button.setMinWidth(size);
            button.setMinHeight(size);
            button.setMinimumWidth(size);
            button.setMinimumHeight(size);
            button.setGravity(android.view.Gravity.CENTER);
            button.setIconGravity(MaterialButton.ICON_GRAVITY_TEXT_START);
            button.setIconSize(dp(27));
            button.setIconPadding(0);
            button.setInsetTop(0);
            button.setInsetBottom(0);
            button.setCornerRadius(size / 2);
            button.setPadding(0, 0, 0, 0);
            button.setScaleX(1f);
            button.setScaleY(1f);
            return;
        }
        if (!(view instanceof ViewGroup)) return;
        ViewGroup group = (ViewGroup) view;
        for (int index = 0; index < group.getChildCount(); index++) {
            centerFunctionIcons(group.getChildAt(index));
        }
    }

    private void configureInlineAlbum() {
        inlineAlbumAdapter = new InlineAlbumAdapter();
        LinearLayoutManager layout = new LinearLayoutManager(this, RecyclerView.HORIZONTAL, false);
        binding.inlineAlbumList.setLayoutManager(layout);
        binding.inlineAlbumList.setAdapter(inlineAlbumAdapter);
        binding.inlineAlbumList.setHasFixedSize(true);
        binding.inlineAlbumList.setItemViewCacheSize(10);
        binding.inlineAlbumList.addOnScrollListener(new RecyclerView.OnScrollListener() {
            @Override public void onScrolled(@NonNull RecyclerView recyclerView, int dx, int dy) {
                int last = layout.findLastVisibleItemPosition();
                if (last >= inlineAlbumDisplayCount - 6 && inlineAlbumDisplayCount < inlineAlbumMedia.size()) {
                    int previous = inlineAlbumDisplayCount;
                    inlineAlbumDisplayCount = Math.min(inlineAlbumMedia.size(), previous + INLINE_ALBUM_BATCH);
                    inlineAlbumAdapter.notifyItemRangeInserted(previous, inlineAlbumDisplayCount - previous);
                }
            }
        });
        installInlineAlbumDragSelection();
        binding.inlineAlbumBack.setOnClickListener(view -> {
            clearInlineAlbumSelection();
            showFunctionPanel();
        });
        binding.inlineAlbumOpen.setOnClickListener(view -> openMediaPicker());
        binding.inlineAlbumOriginal.setChecked(useOriginalMedia);
        binding.inlineAlbumOriginal.setOnCheckedChangeListener((button, checked) -> {
            useOriginalMedia = checked;
            updateInlineAlbumSelectionBar();
        });
        binding.inlineAlbumSend.setOnClickListener(view -> addInlineAlbumSelection());
    }

    private void ensureInlineAlbumPermissionAndLoad() {
        if (hasInlineAlbumPermission()) {
            loadInlineAlbum(false);
            return;
        }
        if (android.os.Build.VERSION.SDK_INT >= 34) {
            requestPermissions(new String[]{
                android.Manifest.permission.READ_MEDIA_IMAGES,
                android.Manifest.permission.READ_MEDIA_VIDEO,
                android.Manifest.permission.READ_MEDIA_VISUAL_USER_SELECTED
            }, REQUEST_MEDIA_PERMISSION);
        } else if (android.os.Build.VERSION.SDK_INT >= 33) {
            requestPermissions(new String[]{
                android.Manifest.permission.READ_MEDIA_IMAGES,
                android.Manifest.permission.READ_MEDIA_VIDEO
            }, REQUEST_MEDIA_PERMISSION);
        } else {
            requestPermissions(new String[]{android.Manifest.permission.READ_EXTERNAL_STORAGE}, REQUEST_MEDIA_PERMISSION);
        }
    }

    private boolean hasInlineAlbumPermission() {
        if (android.os.Build.VERSION.SDK_INT >= 34
            && ContextCompat.checkSelfPermission(this, android.Manifest.permission.READ_MEDIA_VISUAL_USER_SELECTED)
                == PackageManager.PERMISSION_GRANTED) return true;
        if (android.os.Build.VERSION.SDK_INT >= 33) {
            return ContextCompat.checkSelfPermission(this, android.Manifest.permission.READ_MEDIA_IMAGES)
                == PackageManager.PERMISSION_GRANTED
                || ContextCompat.checkSelfPermission(this, android.Manifest.permission.READ_MEDIA_VIDEO)
                == PackageManager.PERMISSION_GRANTED;
        }
        return ContextCompat.checkSelfPermission(this, android.Manifest.permission.READ_EXTERNAL_STORAGE)
            == PackageManager.PERMISSION_GRANTED;
    }

    private void loadInlineAlbum(boolean force) {
        if (inlineAlbumLoading || (!force && !inlineAlbumMedia.isEmpty())) {
            updateInlineAlbumSelectionBar();
            return;
        }
        inlineAlbumLoading = true;
        binding.inlineAlbumEmpty.setText("正在读取最近照片和视频");
        binding.inlineAlbumEmpty.setVisibility(View.VISIBLE);
        mediaExecutor.execute(() -> {
            List<JsonObject> values = queryInlineAlbumMedia();
            runOnUiThread(() -> {
                inlineAlbumLoading = false;
                if (binding == null) return;
                inlineAlbumMedia.clear();
                inlineAlbumMedia.addAll(values);
                rememberInlineAlbumMetadata(values);
                inlineAlbumDisplayCount = Math.min(INLINE_ALBUM_BATCH, inlineAlbumMedia.size());
                inlineAlbumAdapter.notifyDataSetChanged();
                binding.inlineAlbumEmpty.setText(inlineAlbumMedia.isEmpty()
                    ? "暂无可预览的照片和视频" : "");
                binding.inlineAlbumEmpty.setVisibility(inlineAlbumMedia.isEmpty() ? View.VISIBLE : View.GONE);
                updateInlineAlbumSelectionBar();
            });
        });
    }

    private Set<String> inlineAlbumUris(List<JsonObject> items) {
        Set<String> values = new LinkedHashSet<>();
        for (JsonObject item : items) values.add(Jsons.string(item, "url"));
        return values;
    }

    private void rememberInlineAlbumMetadata(List<JsonObject> items) {
        if (items == null || items.isEmpty() || inlineAlbumSelected.isEmpty()) return;
        for (JsonObject item : items) {
            String value = Jsons.string(item, "url");
            if (!value.isEmpty() && inlineAlbumSelected.contains(value)) {
                inlineAlbumSelectionMetadata.put(value, item.deepCopy());
            }
        }
    }

    private void rememberInlineAlbumMetadata(JsonObject item) {
        if (item == null) return;
        String value = Jsons.string(item, "url");
        if (!value.isEmpty()) inlineAlbumSelectionMetadata.put(value, item.deepCopy());
    }

    private List<JsonObject> queryInlineAlbumMedia() {
        List<JsonObject> values = new ArrayList<>();
        queryInlineAlbumCollection(MediaStore.Images.Media.EXTERNAL_CONTENT_URI, false, values);
        queryInlineAlbumCollection(MediaStore.Video.Media.EXTERNAL_CONTENT_URI, true, values);
        values.sort(Comparator.comparingLong(item -> -Jsons.longValue(item, "date_added")));
        if (values.size() > INLINE_ALBUM_MAX) return new ArrayList<>(values.subList(0, INLINE_ALBUM_MAX));
        return values;
    }

    private void queryInlineAlbumCollection(Uri collection, boolean video, List<JsonObject> target) {
        List<String> columns = new ArrayList<>();
        columns.add(MediaStore.MediaColumns._ID);
        columns.add(MediaStore.MediaColumns.MIME_TYPE);
        columns.add(MediaStore.MediaColumns.DISPLAY_NAME);
        columns.add(MediaStore.MediaColumns.SIZE);
        columns.add(MediaStore.MediaColumns.WIDTH);
        columns.add(MediaStore.MediaColumns.HEIGHT);
        columns.add(MediaStore.MediaColumns.DATE_ADDED);
        if (video) columns.add(MediaStore.Video.VideoColumns.DURATION);
        try (Cursor cursor = getContentResolver().query(
            collection, columns.toArray(new String[0]), null, null,
            MediaStore.MediaColumns.DATE_ADDED + " DESC"
        )) {
            if (cursor == null) return;
            int idColumn = cursor.getColumnIndexOrThrow(MediaStore.MediaColumns._ID);
            int mimeColumn = cursor.getColumnIndexOrThrow(MediaStore.MediaColumns.MIME_TYPE);
            int nameColumn = cursor.getColumnIndexOrThrow(MediaStore.MediaColumns.DISPLAY_NAME);
            int sizeColumn = cursor.getColumnIndexOrThrow(MediaStore.MediaColumns.SIZE);
            int widthColumn = cursor.getColumnIndexOrThrow(MediaStore.MediaColumns.WIDTH);
            int heightColumn = cursor.getColumnIndexOrThrow(MediaStore.MediaColumns.HEIGHT);
            int dateColumn = cursor.getColumnIndexOrThrow(MediaStore.MediaColumns.DATE_ADDED);
            int durationColumn = video ? cursor.getColumnIndexOrThrow(MediaStore.Video.VideoColumns.DURATION) : -1;
            int count = 0;
            while (cursor.moveToNext() && count++ < INLINE_ALBUM_MAX) {
                JsonObject item = new JsonObject();
                item.addProperty("url", ContentUris.withAppendedId(collection, cursor.getLong(idColumn)).toString());
                item.addProperty("media_type", video ? "video" : "image");
                item.addProperty("mime_type", cursor.isNull(mimeColumn) ? (video ? "video/*" : "image/*") : cursor.getString(mimeColumn));
                item.addProperty("file_name", cursor.isNull(nameColumn) ? (video ? "本地视频" : "本地图片") : cursor.getString(nameColumn));
                item.addProperty("size_bytes", cursor.isNull(sizeColumn) ? -1 : cursor.getLong(sizeColumn));
                item.addProperty("width", cursor.isNull(widthColumn) ? 0 : cursor.getInt(widthColumn));
                item.addProperty("height", cursor.isNull(heightColumn) ? 0 : cursor.getInt(heightColumn));
                item.addProperty("duration_ms", durationColumn < 0 || cursor.isNull(durationColumn) ? 0 : cursor.getLong(durationColumn));
                item.addProperty("date_added", cursor.isNull(dateColumn) ? 0 : cursor.getLong(dateColumn));
                target.add(item);
            }
        } catch (RuntimeException ignored) {
            // Android 13+ grants image and video access independently.
        }
    }

    private void toggleInlineAlbumSelection(int position) {
        if (position < 0 || position >= inlineAlbumMedia.size()) return;
        JsonObject item = inlineAlbumMedia.get(position);
        String type = Jsons.string(item, "media_type");
        long bytes = Jsons.longValue(item, "size_bytes");
        if (!UploadPolicyStore.accepts(this, type, bytes)) {
            Snackbar.make(binding.getRoot(), UploadPolicyStore.rejectionMessage(this, type, bytes), Snackbar.LENGTH_LONG).show();
            return;
        }
        String value = Jsons.string(item, "url");
        if (inlineAlbumSelected.contains(value)) {
            inlineAlbumSelected.remove(value);
            inlineAlbumSelectionMetadata.remove(value);
        } else if (inlineAlbumSelected.size() < 200) {
            inlineAlbumSelected.add(value);
            rememberInlineAlbumMetadata(item);
        }
        else Snackbar.make(binding.getRoot(), "单次最多选择 200 个媒体", Snackbar.LENGTH_LONG).show();
        inlineAlbumAdapter.notifyItemChanged(position);
        updateInlineAlbumSelectionBar();
    }

    private boolean setInlineAlbumSelection(int position, boolean selected) {
        return setInlineAlbumSelection(position, selected, true);
    }

    private boolean setInlineAlbumSelection(int position, boolean selected, boolean refreshUi) {
        if (position < 0 || position >= inlineAlbumMedia.size()) return false;
        JsonObject item = inlineAlbumMedia.get(position);
        String type = Jsons.string(item, "media_type");
        long bytes = Jsons.longValue(item, "size_bytes");
        if (!UploadPolicyStore.accepts(this, type, bytes)) return false;
        String value = Jsons.string(item, "url");
        if (selected && !inlineAlbumSelected.contains(value) && inlineAlbumSelected.size() >= 200) return false;
        if (selected) {
            inlineAlbumSelected.add(value);
            rememberInlineAlbumMetadata(item);
        } else {
            inlineAlbumSelected.remove(value);
            inlineAlbumSelectionMetadata.remove(value);
        }
        inlineAlbumAdapter.notifyItemChanged(position);
        if (refreshUi) updateInlineAlbumSelectionBar();
        return true;
    }

    private void updateInlineAlbumSelectionBar() {
        if (binding == null) return;
        long bytes = 0;
        boolean sizeUnknown = false;
        for (String value : inlineAlbumSelected) {
            JsonObject item = inlineAlbumSelectionMetadata.get(value);
            long itemBytes = item == null ? -1 : Jsons.longValue(item, "size_bytes");
            if (itemBytes > 0) bytes += itemBytes; else sizeUnknown = true;
        }
        binding.inlineAlbumCount.setText(inlineAlbumSelected.isEmpty() ? "未选择"
            : "已选 " + inlineAlbumSelected.size() + " 项 · "
                + (sizeUnknown && bytes <= 0 ? "大小未知" : mediaSizeText(bytes)));
        binding.inlineAlbumSend.setEnabled(!inlineAlbumSelected.isEmpty());
        binding.inlineAlbumTitle.setText(inlineAlbumMedia.isEmpty() ? "最近照片和视频"
            : "最近照片和视频 · " + inlineAlbumMedia.size());
    }

    private String mediaSizeText(long bytes) {
        if (bytes <= 0) return "大小未知";
        if (bytes < 1024) return bytes + " B";
        double kb = bytes / 1024d;
        if (kb < 1024) return String.format(java.util.Locale.CHINA, "%.1f KB", kb);
        double mb = kb / 1024d;
        if (mb < 1024) return String.format(java.util.Locale.CHINA, "%.1f MB", mb);
        return String.format(java.util.Locale.CHINA, "%.2f GB", mb / 1024d);
    }

    private void addInlineAlbumSelection() {
        if (inlineAlbumSelected.isEmpty()) return;
        ArrayList<Uri> uris = new ArrayList<>();
        pendingPickerMetadata.clear();
        for (String value : inlineAlbumSelected) {
            uris.add(Uri.parse(value));
            JsonObject item = inlineAlbumSelectionMetadata.get(value);
            JsonObject metadata = item == null ? new JsonObject() : item.deepCopy();
            if (!metadata.has("is_gif")) {
                metadata.addProperty("is_gif", item != null && MediaKindDetector.isGif(
                    Jsons.string(item, "mime_type"), Jsons.string(item, "file_name")));
            }
            if (!metadata.has("is_motion_photo")) metadata.addProperty("is_motion_photo", false);
            pendingPickerMetadata.put(value, metadata);
        }
        useOriginalMedia = binding.inlineAlbumOriginal.isChecked();
        selectedUris("file", uris);
        pendingPickerMetadata.clear();
        inlineAlbumSelected.clear();
        inlineAlbumSelectionMetadata.clear();
        inlineAlbumAdapter.notifyDataSetChanged();
        updateInlineAlbumSelectionBar();
        hideMediaPanel();
    }

    private void previewInlineAlbum(int position) {
        if (position < 0 || position >= inlineAlbumMedia.size()) return;
        ImageGalleryActivity.open(this, new ArrayList<>(inlineAlbumMedia), position);
    }

    private void installInlineAlbumDragSelection() {
        binding.inlineAlbumList.addOnItemTouchListener(new RecyclerView.SimpleOnItemTouchListener() {
            @Override public boolean onInterceptTouchEvent(@NonNull RecyclerView recycler, @NonNull MotionEvent event) {
                if (event.getActionMasked() == MotionEvent.ACTION_DOWN) {
                    View child = recycler.findChildViewUnder(event.getX(), event.getY());
                    if (child == null) return false;
                    int position = recycler.getChildAdapterPosition(child);
                    float localX = event.getX() - child.getLeft();
                    float localY = event.getY() - child.getTop();
                    if (position != RecyclerView.NO_POSITION && localX >= child.getWidth() - dp(46) && localY <= dp(46)) {
                        String uri = Jsons.string(inlineAlbumMedia.get(position), "url");
                        inlineAlbumDragValue = !inlineAlbumSelected.contains(uri);
                        inlineAlbumDragSelecting = setInlineAlbumSelection(position, inlineAlbumDragValue);
                        inlineAlbumLastDragPosition = position;
                        inlineAlbumLastTouchX = event.getX();
                        inlineAlbumLastTouchY = event.getY();
                        if (inlineAlbumDragSelecting) recycler.getParent().requestDisallowInterceptTouchEvent(true);
                        return inlineAlbumDragSelecting;
                    }
                } else if (inlineAlbumDragSelecting && event.getActionMasked() == MotionEvent.ACTION_MOVE) {
                    selectInlineAlbumDragPosition(recycler, event);
                    return true;
                } else if (event.getActionMasked() == MotionEvent.ACTION_UP
                    || event.getActionMasked() == MotionEvent.ACTION_CANCEL) {
                    endInlineAlbumDrag(recycler);
                }
                return false;
            }

            @Override public void onTouchEvent(@NonNull RecyclerView recycler, @NonNull MotionEvent event) {
                if (event.getActionMasked() == MotionEvent.ACTION_MOVE) selectInlineAlbumDragPosition(recycler, event);
                if (event.getActionMasked() == MotionEvent.ACTION_UP
                    || event.getActionMasked() == MotionEvent.ACTION_CANCEL) endInlineAlbumDrag(recycler);
            }
        });
    }

    private void selectInlineAlbumDragPosition(RecyclerView recycler, MotionEvent event) {
        float currentX = event.getX();
        float delta = inlineAlbumLastTouchX - currentX;
        if (Math.abs(delta) >= 1f) recycler.scrollBy(Math.round(delta), 0);
        inlineAlbumLastTouchX = currentX;
        inlineAlbumLastTouchY = event.getY();
        selectInlineAlbumDragPosition(recycler, currentX, event.getY());
        updateInlineAlbumEdgeScroll(recycler, currentX);
    }

    private void selectInlineAlbumDragPosition(RecyclerView recycler, float x, float y) {
        float safeX = Math.max(1f, Math.min(recycler.getWidth() - 2f, x));
        float safeY = Math.max(1f, Math.min(recycler.getHeight() - 2f, y));
        View child = recycler.findChildViewUnder(safeX, safeY);
        if (child == null) return;
        int position = recycler.getChildAdapterPosition(child);
        if (position == RecyclerView.NO_POSITION || position == inlineAlbumLastDragPosition) return;
        int start = inlineAlbumLastDragPosition == RecyclerView.NO_POSITION ? position : inlineAlbumLastDragPosition;
        int direction = position >= start ? 1 : -1;
        boolean changed = false;
        for (int index = start + direction; direction > 0 ? index <= position : index >= position; index += direction) {
            changed |= setInlineAlbumSelection(index, inlineAlbumDragValue, false);
        }
        if (changed) updateInlineAlbumSelectionBar();
        inlineAlbumLastDragPosition = position;
    }

    private void updateInlineAlbumEdgeScroll(RecyclerView recycler, float x) {
        int edge = Math.min(dp(64), Math.max(dp(36), recycler.getWidth() / 5));
        if (x <= edge) inlineAlbumEdgeDirection = -1;
        else if (x >= recycler.getWidth() - edge) inlineAlbumEdgeDirection = 1;
        else inlineAlbumEdgeDirection = 0;
        if (inlineAlbumEdgeDirection == 0) {
            recycler.removeCallbacks(inlineAlbumEdgeScroller);
            inlineAlbumEdgeScrollPosted = false;
        } else {
            postInlineAlbumEdgeScroll(recycler);
        }
    }

    private void postInlineAlbumEdgeScroll(RecyclerView recycler) {
        if (inlineAlbumEdgeScrollPosted) return;
        inlineAlbumEdgeScrollPosted = true;
        recycler.postOnAnimation(inlineAlbumEdgeScroller);
    }

    private void endInlineAlbumDrag(RecyclerView recycler) {
        inlineAlbumDragSelecting = false;
        inlineAlbumLastDragPosition = RecyclerView.NO_POSITION;
        inlineAlbumEdgeDirection = 0;
        inlineAlbumEdgeScrollPosted = false;
        recycler.removeCallbacks(inlineAlbumEdgeScroller);
        recycler.getParent().requestDisallowInterceptTouchEvent(false);
    }

    private void openFavoritePicker() {
        dismissRecentSuggestion();
        favoritePicker.launch(FavoriteCenterActivity.pickerIntent(this));
    }

    private void showMentionPicker() {
        if (binding == null || selectionMode) return;
        if (MODE_CONVERSATION.equals(mode())) {
            long peerId = resolvedConversationPeerId();
            String name = getIntent().getStringExtra(EXTRA_TITLE);
            if (name == null || name.trim().isEmpty()) name = "好友";
            showMentionChoices(java.util.Collections.singletonList(
                mentionChoice(peerId, name.trim(), "当前私聊对象")));
            return;
        }
        if (MODE_SERVICE_USER.equals(mode())) {
            showMentionChoices(java.util.Collections.singletonList(
                mentionChoice(0, "客服", "在线客服")));
            return;
        }
        if (!MODE_ROOM.equals(mode()) || mentionRequest != null) return;
        long roomId = getIntent().getLongExtra(EXTRA_TARGET_ID, 0);
        if (roomId <= 0) return;
        LinkedHashMap<String, String> query = new LinkedHashMap<>();
        query.put("page", "1");
        query.put("limit", "200");
        mentionRequest = AppAccess.from(this).repository().get(
            "/api/user/chat-rooms/" + roomId + "/members", query, result -> {
                mentionRequest = null;
                if (binding == null || !result.isSuccessful()) return;
                List<JsonObject> choices = new ArrayList<>();
                long selfId = AppAccess.from(this).session().actorId();
                for (JsonElement element : Jsons.array(result.dataObject(), "items")) {
                    if (!element.isJsonObject()) continue;
                    JsonObject member = element.getAsJsonObject();
                    long userId = Jsons.longValue(member, "user_id");
                    if (userId <= 0 || userId == selfId) continue;
                    String name = Jsons.string(member, "group_nickname");
                    if (name.isEmpty()) name = Jsons.string(member, "nickname");
                    if (name.isEmpty()) name = Jsons.string(member, "account");
                    choices.add(mentionChoice(userId, name, "UID " + Jsons.string(member, "uid")));
                }
                showMentionChoices(choices);
            });
    }

    private JsonObject mentionChoice(long userId, String name, String detail) {
        JsonObject item = new JsonObject();
        item.addProperty("user_id", userId);
        item.addProperty("name", name == null || name.trim().isEmpty() ? "用户" : name.trim());
        item.addProperty("detail", detail == null ? "" : detail);
        return item;
    }

    private void showMentionChoices(List<JsonObject> choices) {
        if (binding == null || choices == null || choices.isEmpty()) return;
        String[] labels = new String[choices.size()];
        for (int index = 0; index < choices.size(); index++) {
            JsonObject item = choices.get(index);
            String detail = Jsons.string(item, "detail");
            labels[index] = Jsons.string(item, "name") + (detail.isEmpty() ? "" : "  ·  " + detail);
        }
        new YiyunyingDialogBuilder(this)
            .setTitle("选择要提醒的人")
            .setItems(labels, (dialog, which) -> insertMention(choices.get(which)))
            .setNegativeButton("取消", null)
            .show();
    }

    private void insertMention(JsonObject choice) {
        Editable editable = binding.messageInput.getText();
        if (editable == null) return;
        int cursor = Math.max(0, binding.messageInput.getSelectionStart());
        int at = editable.toString().lastIndexOf('@', Math.max(0, cursor - 1));
        if (at < 0) at = cursor;
        String value = "@" + Jsons.string(choice, "name") + " ";
        suppressMentionPicker = true;
        editable.replace(at, cursor, value);
        suppressMentionPicker = false;
        long userId = Jsons.longValue(choice, "user_id");
        if (userId > 0) pendingMentionIds.add(userId);
        binding.messageInput.requestFocus();
        binding.messageInput.setSelection(Math.min(editable.length(), at + value.length()));
    }

    private void handleFavoritePickerResult(ActivityResult result) {
        if (result.getResultCode() != RESULT_OK || result.getData() == null) return;
        String raw = result.getData().getStringExtra(FavoriteCenterActivity.EXTRA_SELECTED_ITEMS);
        if (raw == null || raw.trim().isEmpty()) {
            raw = result.getData().getStringExtra(FavoriteCenterActivity.EXTRA_SELECTED_ITEM);
        }
        if (raw == null || raw.trim().isEmpty()) return;
        try {
            JsonElement parsed = JsonParser.parseString(raw);
            JsonArray selected = new JsonArray();
            if (parsed.isJsonArray()) selected = parsed.getAsJsonArray();
            else if (parsed.isJsonObject()) selected.add(parsed.getAsJsonObject());
            for (JsonElement element : selected) {
                if (element.isJsonObject()) sendFavoriteAttachment(element.getAsJsonObject());
            }
        } catch (RuntimeException exception) {
            Snackbar.make(binding.getRoot(), "收藏内容无法读取，请刷新收藏后重试", Snackbar.LENGTH_LONG).show();
        }
    }

    private void sendFavoriteAttachment(JsonObject item) {
        JsonObject metadata = item.deepCopy();
        metadata.addProperty("favorite_type", Jsons.string(item, "favorite_type"));
        metadata.addProperty("target_id", Jsons.longValue(item, "target_id"));
        sendBusinessAttachment("favorite", Jsons.string(item, "title"),
            Jsons.string(item, "preview_url"), metadata, "收藏");
    }

    private void showFunctionPanel() {
        if (binding.mediaPanel.getVisibility() == View.VISIBLE && binding.functionPane.getVisibility() == View.VISIBLE) {
            hideMediaPanel();
            return;
        }
        if (binding.inlineAlbumPane.getVisibility() == View.VISIBLE) clearInlineAlbumSelection();
        showPanel(binding.functionPane);
        binding.functionPager.post(() -> configureFunctionPageWidths(true));
    }

    private void showEmojiPanel() {
        if (binding.mediaPanel.getVisibility() == View.VISIBLE && binding.emojiPane.getVisibility() == View.VISIBLE) {
            hideMediaPanel();
            return;
        }
        showPanel(binding.emojiPane);
    }

    private void showStickerPanel() {
        showPanel(binding.stickerPane);
        loadStickers();
    }

    private void showInlineAlbumPanel() {
        showPanel(binding.inlineAlbumPane);
        binding.inlineAlbumOriginal.setChecked(useOriginalMedia);
        ensureInlineAlbumPermissionAndLoad();
    }

    private void showPanel(View panel) {
        ViewportAnchor anchor = captureHistoryViewportAnchor();
        dismissRecentSuggestion();
        binding.messageInput.clearFocus();
        android.view.inputmethod.InputMethodManager keyboard =
            (android.view.inputmethod.InputMethodManager) getSystemService(Context.INPUT_METHOD_SERVICE);
        keyboard.hideSoftInputFromWindow(binding.messageInput.getWindowToken(), 0);
        binding.functionPane.setVisibility(panel == binding.functionPane ? View.VISIBLE : View.GONE);
        binding.emojiPane.setVisibility(panel == binding.emojiPane ? View.VISIBLE : View.GONE);
        binding.stickerPane.setVisibility(panel == binding.stickerPane ? View.VISIBLE : View.GONE);
        binding.inlineAlbumPane.setVisibility(panel == binding.inlineAlbumPane ? View.VISIBLE : View.GONE);
        boolean opening = binding.mediaPanel.getVisibility() != View.VISIBLE;
        binding.mediaPanel.setVisibility(View.VISIBLE);
        binding.attachButton.animate().rotation(45f).setDuration(150L).start();
        if (opening) {
            binding.mediaPanel.setAlpha(0f);
            binding.mediaPanel.setTranslationY(dp(60));
            binding.mediaPanel.animate().alpha(1f).translationY(0f).setDuration(180L).start();
        }
        scheduleViewportAnchorRestore(anchor, 0L, 90L, 220L, 340L);
        binding.recycler.post(this::renderNewMessageIndicator);
    }

    private void hideMediaPanel() {
        if (binding == null || binding.mediaPanel.getVisibility() != View.VISIBLE) return;
        ViewportAnchor anchor = captureHistoryViewportAnchor();
        clearInlineAlbumSelection();
        binding.attachButton.animate().rotation(0f).setDuration(130L).start();
        binding.mediaPanel.animate().alpha(0f).translationY(dp(40)).setDuration(140L)
            .withEndAction(() -> {
                if (binding == null) return;
                binding.mediaPanel.setVisibility(View.GONE);
                binding.mediaPanel.setTranslationY(0f);
                scheduleViewportAnchorRestore(anchor, 0L, 100L, 260L);
            }).start();
    }

    private void clearInlineAlbumSelection() {
        inlineAlbumDragSelecting = false;
        inlineAlbumLastDragPosition = RecyclerView.NO_POSITION;
        inlineAlbumEdgeDirection = 0;
        inlineAlbumEdgeScrollPosted = false;
        if (binding != null) {
            binding.inlineAlbumList.removeCallbacks(inlineAlbumEdgeScroller);
            binding.inlineAlbumList.getParent().requestDisallowInterceptTouchEvent(false);
        }
        boolean hadSelection = !inlineAlbumSelected.isEmpty();
        inlineAlbumSelected.clear();
        inlineAlbumSelectionMetadata.clear();
        mediaPickerInitialSelection.clear();
        if (hadSelection && inlineAlbumAdapter != null) inlineAlbumAdapter.notifyDataSetChanged();
        updateInlineAlbumSelectionBar();
    }

    private void bindAction(View view, View.OnClickListener listener) {
        view.setClickable(true);
        view.setFocusable(true);
        view.setOnTouchListener(null);
        final long[] lastClickAt = {0L};
        view.setOnClickListener(target -> {
            long now = android.os.SystemClock.elapsedRealtime();
            if (now - lastClickAt[0] < 280L) return;
            lastClickAt[0] = now;
            listener.onClick(view);
        });
        if (!(view instanceof ViewGroup)) return;
        bindChildClickTargets((ViewGroup) view, view);
    }

    private void bindChildClickTargets(ViewGroup group, View actionRoot) {
        for (int index = 0; index < group.getChildCount(); index++) {
            View child = group.getChildAt(index);
            child.setClickable(true);
            child.setFocusable(false);
            child.setOnTouchListener(null);
            child.setOnClickListener(target -> actionRoot.performClick());
            if (child instanceof ViewGroup) bindChildClickTargets((ViewGroup) child, actionRoot);
        }
    }

    private void showCaptureOptions() {
        dismissRecentSuggestion();
        BottomSheetCaptureBinding sheet = BottomSheetCaptureBinding.inflate(getLayoutInflater());
        BottomSheetDialog dialog = new BottomSheetDialog(this);
        dialog.setContentView(sheet.getRoot());
        boolean saved = CapturePreferences.optimizeBeforeSend(this);
        sheet.optimizeSwitch.setChecked(saved);
        View.OnClickListener photo = view -> {
            optimizeCapturedMedia = sheet.optimizeSwitch.isChecked();
            saveCapturePreference(optimizeCapturedMedia);
            dialog.dismiss();
            launchCapture(false);
        };
        View.OnClickListener video = view -> {
            optimizeCapturedMedia = sheet.optimizeSwitch.isChecked();
            saveCapturePreference(optimizeCapturedMedia);
            dialog.dismiss();
            launchCapture(true);
        };
        sheet.photoOption.setOnClickListener(photo);
        sheet.videoOption.setOnClickListener(video);
        sheet.cancelButton.setOnClickListener(view -> dialog.dismiss());
        GlassBottomSheet.prepare(dialog, this, 0.72f, false);
        dialog.show();
    }

    private void saveCapturePreference(boolean enabled) {
        CapturePreferences.setOptimizeBeforeSend(this, enabled);
    }

    private void handleCapturedMedia(Uri captured, boolean video) {
        lastCapturedMediaUri = captured;
        dismissRecentSuggestion();
        if (!optimizeCapturedMedia) {
            selectedUris(video ? "video" : "image", java.util.Collections.singletonList(captured));
            return;
        }
        if (binding != null) {
            binding.progress.setVisibility(View.VISIBLE);
            Snackbar.make(binding.getRoot(), video ? "正在本地优化录像，请稍候" : "正在本地优化照片", Snackbar.LENGTH_LONG).show();
        }
        LocalMediaOptimizer.optimize(this, captured, video, result -> {
            if (binding == null) return;
            binding.progress.setVisibility(View.INVISIBLE);
            Uri output = result.uri == null || Uri.EMPTY.equals(result.uri) ? captured : result.uri;
            lastCapturedMediaUri = output;
            selectedUris(video ? "video" : "image", java.util.Collections.singletonList(output));
            String size = result.optimized
                ? "（" + readableBytes(result.originalBytes) + " → " + readableBytes(result.outputBytes) + "）"
                : "";
            Snackbar.make(binding.getRoot(), result.message + size, Snackbar.LENGTH_LONG).show();
        });
    }

    private String readableBytes(long bytes) {
        if (bytes <= 0) return "大小未知";
        if (bytes >= 1073741824L) return String.format(java.util.Locale.CHINA, "%.2f GB", bytes / 1073741824d);
        if (bytes >= 1048576L) return String.format(java.util.Locale.CHINA, "%.1f MB", bytes / 1048576d);
        return String.format(java.util.Locale.CHINA, "%.1f KB", bytes / 1024d);
    }

    private void launchCapture(boolean video) {
        pendingVideoCapture = video;
        if (ContextCompat.checkSelfPermission(this, android.Manifest.permission.CAMERA) != PackageManager.PERMISSION_GRANTED) {
            requestPermissions(new String[]{android.Manifest.permission.CAMERA}, REQUEST_CAMERA_PERMISSION);
            return;
        }
        createCaptureTarget(video);
    }

    private void createCaptureTarget(boolean video) {
        cameraTargetInGallery = CapturePreferences.saveToGallery(this);
        if (!cameraTargetInGallery) {
            try {
                File directory = new File(getCacheDir(), "captures");
                if (!directory.exists() && !directory.mkdirs()) {
                    throw new IllegalStateException("无法创建拍摄缓存目录");
                }
                cameraCacheFile = File.createTempFile(video ? "video_" : "photo_", video ? ".mp4" : ".jpg", directory);
                cameraUri = FileProvider.getUriForFile(this, getPackageName() + ".capture-files", cameraCacheFile);
                if (video) videoCapture.launch(cameraUri); else cameraCapture.launch(cameraUri);
                return;
            } catch (Exception exception) {
                cameraCacheFile = null;
                cameraUri = null;
                Snackbar.make(binding.getRoot(), "无法创建应用拍摄缓存：" + exception.getMessage(), Snackbar.LENGTH_LONG).show();
                return;
            }
        }
        ContentValues values = new ContentValues();
        values.put(MediaStore.MediaColumns.DISPLAY_NAME,
            (video ? "易运盈录像_" : "易运盈拍照_") + System.currentTimeMillis() + (video ? ".mp4" : ".jpg"));
        values.put(MediaStore.MediaColumns.MIME_TYPE, video ? "video/mp4" : "image/jpeg");
        if (android.os.Build.VERSION.SDK_INT >= 29) {
            values.put(MediaStore.MediaColumns.RELATIVE_PATH, android.os.Environment.DIRECTORY_DCIM + "/yyyht");
            values.put(MediaStore.MediaColumns.IS_PENDING, 1);
        }
        cameraUri = getContentResolver().insert(
            video ? MediaStore.Video.Media.EXTERNAL_CONTENT_URI : MediaStore.Images.Media.EXTERNAL_CONTENT_URI,
            values
        );
        if (cameraUri == null) {
            Snackbar.make(binding.getRoot(), "无法创建拍摄文件，请检查系统存储权限", Snackbar.LENGTH_LONG).show();
            return;
        }
        if (video) videoCapture.launch(cameraUri); else cameraCapture.launch(cameraUri);
    }

    private void publishCapturedMedia(Uri uri) {
        if (!cameraTargetInGallery || uri == null || android.os.Build.VERSION.SDK_INT < 29) return;
        try {
            ContentValues values = new ContentValues();
            values.put(MediaStore.MediaColumns.IS_PENDING, 0);
            getContentResolver().update(uri, values, null, null);
        } catch (RuntimeException ignored) { }
    }

    private void discardCaptureTarget() {
        if (cameraTargetInGallery && cameraUri != null) {
            try { getContentResolver().delete(cameraUri, null, null); } catch (RuntimeException ignored) { }
        }
        if (cameraCacheFile != null && cameraCacheFile.exists()) {
            //noinspection ResultOfMethodCallIgnored
            cameraCacheFile.delete();
        }
    }

    private void openMediaPicker() {
        dismissRecentSuggestion();
        mediaPickerOpenedFromInlineAlbum = binding != null
            && binding.mediaPanel.getVisibility() == View.VISIBLE
            && binding.inlineAlbumPane.getVisibility() == View.VISIBLE;
        mediaPickerInitialSelection.clear();
        for (PendingAttachment attachment : pendingAttachments) {
            if (attachment.uri == null) continue;
            if ("image".equals(attachment.mediaType) || "video".equals(attachment.mediaType)) {
                mediaPickerInitialSelection.add(attachment.uri.toString());
            }
        }
        mediaPickerInitialSelection.addAll(inlineAlbumSelected);
        ArrayList<Uri> initial = new ArrayList<>();
        for (String value : mediaPickerInitialSelection) initial.add(Uri.parse(value));
        mediaPicker.launch(MediaPickerActivity.intent(this, useOriginalMedia, initial));
    }

    private void handleMediaPickerResult(ActivityResult result) {
        if (result.getResultCode() != RESULT_OK || result.getData() == null) {
            syncInlineAlbumSelection(mediaPickerInitialSelection, null);
            mediaPickerInitialSelection.clear();
            restoreInlineAlbumAfterPicker();
            return;
        }
        Intent data = result.getData();
        ArrayList<Uri> uris = data.getParcelableArrayListExtra(MediaPickerActivity.EXTRA_SELECTED_URIS);
        Set<String> selectedValues = new LinkedHashSet<>();
        if (uris != null) for (Uri uri : uris) if (uri != null) selectedValues.add(uri.toString());
        Map<String, JsonObject> returnedMetadata = parsePickerMetadata(data);
        syncInlineAlbumSelection(selectedValues, returnedMetadata);
        useOriginalMedia = data.getBooleanExtra(MediaPickerActivity.EXTRA_ORIGINAL, false);
        boolean confirmed = data.getBooleanExtra(MediaPickerActivity.EXTRA_SELECTION_CONFIRMED, true);
        if (!confirmed) {
            mediaPickerInitialSelection.clear();
            pendingPickerMetadata.clear();
            restoreInlineAlbumAfterPicker();
            return;
        }
        pendingAttachments.removeIf(attachment -> attachment.uri != null
            && mediaPickerInitialSelection.contains(attachment.uri.toString())
            && !selectedValues.contains(attachment.uri.toString()));
        pendingPickerMetadata.clear();
        pendingPickerMetadata.putAll(returnedMetadata);
        selectedUris("file", uris == null ? java.util.Collections.emptyList() : uris);
        mediaPickerInitialSelection.clear();
        pendingPickerMetadata.clear();
        dismissRecentSuggestion();
        restoreInlineAlbumAfterPicker();
    }

    private void restoreInlineAlbumAfterPicker() {
        boolean shouldRestore = mediaPickerOpenedFromInlineAlbum;
        mediaPickerOpenedFromInlineAlbum = false;
        if (!shouldRestore || binding == null || isFinishing() || isDestroyed()) return;
        // Rebind the inline list to the same selection session. Merely making the pane
        // visible left recycled cells unchecked after returning from the complete gallery.
        showInlineAlbumPanel();
        binding.inlineAlbumOriginal.setChecked(useOriginalMedia);
        updateInlineAlbumSelectionBar();
    }

    private Map<String, JsonObject> parsePickerMetadata(Intent data) {
        Map<String, JsonObject> result = new LinkedHashMap<>();
        if (data == null) return result;
        try {
            JsonObject values = JsonParser.parseString(
                data.getStringExtra(MediaPickerActivity.EXTRA_MEDIA_METADATA)).getAsJsonObject();
            for (Map.Entry<String, com.google.gson.JsonElement> entry : values.entrySet()) {
                if (entry.getValue().isJsonObject()) {
                    result.put(entry.getKey(), entry.getValue().getAsJsonObject().deepCopy());
                }
            }
        } catch (RuntimeException ignored) { }
        return result;
    }

    private void syncInlineAlbumSelection(java.util.Collection<String> selectedValues,
                                          Map<String, JsonObject> metadata) {
        inlineAlbumSelected.clear();
        if (selectedValues != null) {
            for (String value : selectedValues) {
                if (value != null && !value.trim().isEmpty()) inlineAlbumSelected.add(value);
            }
        }
        inlineAlbumSelectionMetadata.keySet().retainAll(inlineAlbumSelected);
        if (metadata != null) {
            for (Map.Entry<String, JsonObject> entry : metadata.entrySet()) {
                if (inlineAlbumSelected.contains(entry.getKey()) && entry.getValue() != null) {
                    inlineAlbumSelectionMetadata.put(entry.getKey(), entry.getValue().deepCopy());
                }
            }
        }
        rememberInlineAlbumMetadata(inlineAlbumMedia);
        if (inlineAlbumAdapter != null) inlineAlbumAdapter.notifyDataSetChanged();
        updateInlineAlbumSelectionBar();
    }

    private void loadRecentSuggestion() {
        boolean allowed = android.os.Build.VERSION.SDK_INT >= 34
            ? ContextCompat.checkSelfPermission(this, android.Manifest.permission.READ_MEDIA_IMAGES) == PackageManager.PERMISSION_GRANTED
                || ContextCompat.checkSelfPermission(this, android.Manifest.permission.READ_MEDIA_VISUAL_USER_SELECTED) == PackageManager.PERMISSION_GRANTED
            : ContextCompat.checkSelfPermission(this, android.os.Build.VERSION.SDK_INT >= 33
                ? android.Manifest.permission.READ_MEDIA_IMAGES : android.Manifest.permission.READ_EXTERNAL_STORAGE) == PackageManager.PERMISSION_GRANTED;
        if (!allowed) {
            binding.recentSuggestion.setVisibility(View.GONE);
            return;
        }
        if (recentSuggestionQueryInFlight) {
            recentSuggestionRecheckPending = true;
            return;
        }
        recentSuggestionQueryInFlight = true;
        mediaExecutor.execute(() -> {
            JsonObject recent = null;
            String[] projection = {
                MediaStore.Images.Media._ID,
                MediaStore.Images.Media.DISPLAY_NAME,
                MediaStore.Images.Media.MIME_TYPE,
                MediaStore.Images.Media.SIZE,
                MediaStore.Images.Media.WIDTH,
                MediaStore.Images.Media.HEIGHT,
                MediaStore.Images.Media.DATE_ADDED
            };
            try (Cursor cursor = getContentResolver().query(
                MediaStore.Images.Media.EXTERNAL_CONTENT_URI,
                projection,
                null,
                null,
                MediaStore.Images.Media.DATE_ADDED + " DESC, " + MediaStore.Images.Media._ID + " DESC"
            )) {
                if (cursor != null && cursor.moveToFirst()) {
                    recent = new JsonObject();
                    Uri uri = Uri.withAppendedPath(MediaStore.Images.Media.EXTERNAL_CONTENT_URI, String.valueOf(cursor.getLong(0)));
                    recent.addProperty("url", uri.toString());
                    recent.addProperty("media_type", "image");
                    recent.addProperty("file_name", cursor.isNull(1) ? "最近照片" : cursor.getString(1));
                    recent.addProperty("mime_type", cursor.isNull(2) ? "image/*" : cursor.getString(2));
                    recent.addProperty("size_bytes", cursor.isNull(3) ? -1 : cursor.getLong(3));
                    recent.addProperty("width", cursor.isNull(4) ? 0 : cursor.getInt(4));
                    recent.addProperty("height", cursor.isNull(5) ? 0 : cursor.getInt(5));
                    long addedAt = cursor.isNull(6) ? 0 : cursor.getLong(6);
                    recent.addProperty("recent_media_id", cursor.getLong(0));
                    recent.addProperty("recent_added_at", addedAt);
                    recent.addProperty("recent_signature", addedAt + ":" + cursor.getLong(0));
                }
            } catch (RuntimeException ignored) { }
            JsonObject value = recent;
            runOnUiThread(() -> {
                recentSuggestionQueryInFlight = false;
                showRecentSuggestion(value);
                if (recentSuggestionRecheckPending && running) {
                    recentSuggestionRecheckPending = false;
                    loadRecentSuggestion();
                }
            });
        });
    }

    private void showRecentSuggestion(JsonObject media) {
        if (binding == null || !running) return;
        long currentId = media == null ? 0L : Jsons.longValue(media, "recent_media_id");
        long currentAddedAt = media == null ? 0L : Jsons.longValue(media, "recent_added_at");
        android.content.SharedPreferences preferences = getSharedPreferences("chat_experience", MODE_PRIVATE);
        boolean markerReady = preferences.contains("recent_photo_latest_id")
            && preferences.contains("recent_photo_latest_added_at");
        RecentPhotoSuggestionPolicy.Decision decision = RecentPhotoSuggestionPolicy.decide(
            preferences.getBoolean("recent_photo_baseline_ready", false) && markerReady,
            preferences.getLong("recent_photo_latest_added_at", 0L),
            preferences.getLong("recent_photo_latest_id", 0L),
            currentAddedAt,
            currentId
        );
        if (decision == RecentPhotoSuggestionPolicy.Decision.INITIALIZE) {
            preferences.edit()
                .putBoolean("recent_photo_baseline_ready", true)
                .putLong("recent_photo_latest_added_at", currentAddedAt)
                .putLong("recent_photo_latest_id", currentId)
                .putString("recent_photo_signature", media == null ? "" : Jsons.string(media, "recent_signature"))
                .apply();
            binding.recentSuggestion.setVisibility(View.GONE);
            return;
        }
        if (decision != RecentPhotoSuggestionPolicy.Decision.SHOW || media == null) return;
        // Claim the new gallery item globally before rendering so another chat cannot repeat the hint.
        preferences.edit()
            .putLong("recent_photo_latest_added_at", currentAddedAt)
            .putLong("recent_photo_latest_id", currentId)
            .putString("recent_photo_signature", Jsons.string(media, "recent_signature"))
            .apply();
        Uri uri = Uri.parse(Jsons.string(media, "url"));
        long size = Jsons.longValue(media, "size_bytes");
        if (!UploadPolicyStore.accepts(this, "image", size)) {
            binding.recentSuggestion.setVisibility(View.GONE);
            return;
        }
        recentMedia.clear();
        recentMedia.add(media);
        recentSuggestionUri = uri;
        binding.recentSuggestionName.setText(Jsons.string(media, "file_name"));
        com.bumptech.glide.Glide.with(binding.recentSuggestionImage).load(uri).centerCrop()
            .placeholder(R.drawable.ic_album).into(binding.recentSuggestionImage);
        binding.recentSuggestionImage.setOnClickListener(view -> {
            InlineMediaPreviewDialog.show(this, recentMedia, 0);
            dismissRecentSuggestion();
        });
        binding.recentSuggestionAdd.setOnClickListener(view -> {
            if (!containsUri(uri)) selectedUris("image", java.util.Collections.singletonList(uri));
            dismissRecentSuggestion();
        });
        binding.recentSuggestion.setOnClickListener(view -> dismissRecentSuggestion());
        binding.recentSuggestion.setVisibility(View.VISIBLE);
    }

    private void dismissRecentSuggestion() {
        recentSuggestionUri = null;
        recentMedia.clear();
        if (binding != null) {
            binding.recentSuggestion.setVisibility(View.GONE);
            com.bumptech.glide.Glide.with(binding.recentSuggestionImage).clear(binding.recentSuggestionImage);
        }
    }

    private void removeUri(Uri uri) {
        pendingAttachments.removeIf(attachment -> uri.equals(attachment.uri));
        renderPendingAttachments();
    }

    private void editMessageTags() {
        android.widget.EditText input = new android.widget.EditText(this);
        input.setHint("例如：工作,重要,图片");
        input.setText(String.join(",", pendingTags));
        new YiyunyingDialogBuilder(this).setTitle("消息标签").setView(input)
            .setPositiveButton("保存", (dialog, which) -> {
                pendingTags.clear();
                for (String tag : input.getText().toString().split("[,，]")) {
                    String value = tag.trim(); if (!value.isEmpty() && pendingTags.size() < 10) pendingTags.add(value);
                }
                Snackbar.make(binding.getRoot(), pendingTags.isEmpty() ? "已清空消息标签" : "已设置 " + pendingTags.size() + " 个消息标签", Snackbar.LENGTH_SHORT).show();
            }).setNegativeButton("取消", null).show();
    }

    private void toggleVoiceMode() {
        voiceMode = !voiceMode;
        hideMediaPanel();
        binding.messageInputLayout.setVisibility(voiceMode ? View.INVISIBLE : View.VISIBLE);
        binding.holdToTalkButton.setVisibility(voiceMode ? View.VISIBLE : View.GONE);
        binding.voiceModeButton.setIconResource(voiceMode ? R.drawable.ic_keyboard : R.drawable.ic_voice);
        binding.voiceModeButton.setContentDescription(voiceMode ? "切换文字输入" : "切换按住说话");
        if (!voiceMode) {
            binding.messageInput.requestFocus();
            android.view.inputmethod.InputMethodManager keyboard = (android.view.inputmethod.InputMethodManager) getSystemService(Context.INPUT_METHOD_SERVICE);
            keyboard.showSoftInput(binding.messageInput, android.view.inputmethod.InputMethodManager.SHOW_IMPLICIT);
        } else {
            android.view.inputmethod.InputMethodManager keyboard = (android.view.inputmethod.InputMethodManager) getSystemService(Context.INPUT_METHOD_SERVICE);
            keyboard.hideSoftInputFromWindow(binding.messageInput.getWindowToken(), 0);
        }
        updateComposerActions();
    }

    private void startSpeechRecognition() {
        if (serverSpeechRecording) {
            finishServerSpeechRecording();
            return;
        }
        if (ContextCompat.checkSelfPermission(this, android.Manifest.permission.RECORD_AUDIO) != PackageManager.PERMISSION_GRANTED) {
            pendingSpeechPermission = true;
            pendingServerSpeechPermission = false;
            requestPermissions(new String[]{android.Manifest.permission.RECORD_AUDIO}, REQUEST_SPEECH_PERMISSION);
            return;
        }
        if (speechListening) {
            requestSpeechStop();
            return;
        }
        startPlatformSpeechRecognition();
    }

    private void startPlatformSpeechRecognition() {
        if (!SpeechRecognizer.isRecognitionAvailable(this)) {
            startOfflineSpeechRecognition();
            return;
        }
        if (speechEngine == null) createSpeechEngine();
        if (speechEngine == null) {
            startOfflineSpeechRecognition();
            return;
        }
        Intent intent = new Intent(RecognizerIntent.ACTION_RECOGNIZE_SPEECH);
        configureBilingualSpeechIntent(intent);
        intent.putExtra(RecognizerIntent.EXTRA_MAX_RESULTS, 3);
        intent.putExtra(RecognizerIntent.EXTRA_SPEECH_INPUT_COMPLETE_SILENCE_LENGTH_MILLIS,
            SPEECH_SILENCE_TIMEOUT_MS);
        intent.putExtra(RecognizerIntent.EXTRA_SPEECH_INPUT_POSSIBLY_COMPLETE_SILENCE_LENGTH_MILLIS,
            SPEECH_SILENCE_TIMEOUT_MS);
        beginSpeechUi();
        platformSpeechListening = true;
        try {
            speechEngine.startListening(intent);
        } catch (RuntimeException exception) {
            finishSpeechUi();
            startOfflineSpeechRecognition();
        }
    }

    private void startOfflineSpeechRecognition() {
        if (offlineSpeech == null) {
            startServerSpeechRecording("");
            return;
        }
        beginSpeechUi();
        offlineSpeechListening = true;
        try {
            offlineSpeech.start();
        } catch (RuntimeException error) {
            finishSpeechUi();
            startServerSpeechRecording("");
        }
    }

    private void prepareOfflineSpeech() {
        offlineSpeech = OfflineSpeechTranscriber.create(this, new OfflineSpeechTranscriber.Listener() {
            @Override public void onReady() { }

            @Override public void onListening() {
                if (binding == null || !speechListening) return;
                markSpeechActivity();
            }

            @Override public void onPartialResult(String text) {
                if (binding == null || !speechListening) return;
                String value = text == null ? "" : text.trim();
                if (!value.isEmpty() && !value.equals(speechPartialText)) markSpeechActivity();
                speechPartialText = value;
            }

            @Override public void onFinalResult(String text) {
                speechStopRequested = false;
                finishSpeechUi();
                commitSpeechText(text);
            }

            @Override public void onError(String message) {
                boolean wasListening = speechListening;
                boolean stopped = speechStopRequested;
                finishSpeechUi();
                if (wasListening && !stopped && binding != null) startServerSpeechRecording("");
            }
        });
        if (offlineSpeech != null) offlineSpeech.prepare();
    }

    private void startSystemSpeechActivity() {
        try {
            Intent intent = new Intent(RecognizerIntent.ACTION_RECOGNIZE_SPEECH);
            configureBilingualSpeechIntent(intent);
            speechRecognizer.launch(intent);
        } catch (ActivityNotFoundException exception) {
            startOfflineSpeechRecognition();
        }
    }

    private void configureBilingualSpeechIntent(Intent intent) {
        intent.putExtra(RecognizerIntent.EXTRA_LANGUAGE_MODEL, RecognizerIntent.LANGUAGE_MODEL_FREE_FORM);
        intent.putExtra(RecognizerIntent.EXTRA_LANGUAGE, "zh-CN");
        intent.putExtra(RecognizerIntent.EXTRA_LANGUAGE_PREFERENCE, "zh-CN,en-US");
        intent.putExtra(RecognizerIntent.EXTRA_PARTIAL_RESULTS, true);
        if (android.os.Build.VERSION.SDK_INT >= android.os.Build.VERSION_CODES.M) {
            // Prefer the device recognizer's bilingual model when online. If it is unavailable,
            // the existing offline model and server-side language=auto path remain as fallbacks.
            intent.putExtra(RecognizerIntent.EXTRA_PREFER_OFFLINE, false);
        }
        if (android.os.Build.VERSION.SDK_INT >= 34) {
            ArrayList<String> languages = new ArrayList<>();
            languages.add("zh-CN");
            languages.add("en-US");
            intent.putExtra("android.speech.extra.ENABLE_LANGUAGE_DETECTION", true);
            intent.putStringArrayListExtra(
                "android.speech.extra.LANGUAGE_DETECTION_ALLOWED_LANGUAGES", languages);
            intent.putExtra("android.speech.extra.ENABLE_LANGUAGE_SWITCH", "balanced");
            intent.putStringArrayListExtra(
                "android.speech.extra.LANGUAGE_SWITCH_ALLOWED_LANGUAGES", languages);
        }
    }

    private void createSpeechEngine() {
        try {
            speechEngine = SpeechRecognizer.createSpeechRecognizer(this);
            speechEngine.setRecognitionListener(new RecognitionListener() {
                @Override public void onReadyForSpeech(Bundle params) { markSpeechActivity(); }
                @Override public void onBeginningOfSpeech() { markSpeechActivity(); }
                @Override public void onRmsChanged(float rmsdB) {
                    if (rmsdB > 1.5f) markSpeechActivity();
                }
                @Override public void onBufferReceived(byte[] buffer) { }
                @Override public void onEndOfSpeech() { }
                @Override public void onEvent(int eventType, Bundle params) { }
                @Override public void onPartialResults(Bundle partialResults) {
                    ArrayList<String> values = partialResults.getStringArrayList(SpeechRecognizer.RESULTS_RECOGNITION);
                    if (binding != null && values != null && !values.isEmpty()) {
                        String value = values.get(0) == null ? "" : values.get(0).trim();
                        if (!value.isEmpty() && !value.equals(speechPartialText)) markSpeechActivity();
                        speechPartialText = value;
                    }
                }
                @Override public void onResults(Bundle results) {
                    ArrayList<String> values = results.getStringArrayList(SpeechRecognizer.RESULTS_RECOGNITION);
                    String finalText = values != null && !values.isEmpty() ? values.get(0) : speechPartialText;
                    speechStopRequested = false;
                    finishSpeechUi();
                    commitSpeechText(finalText);
                }
                @Override public void onError(int error) {
                    boolean stoppedByUser = speechStopRequested;
                    String partial = speechPartialText;
                    speechStopRequested = false;
                    finishSpeechUi();
                    if (binding == null) return;
                    if (!partial.isEmpty()) {
                        commitSpeechText(partial);
                        return;
                    }
                    if (!stoppedByUser) startOfflineSpeechRecognition();
                }
            });
        } catch (RuntimeException ignored) {
            speechEngine = null;
        }
    }

    private void finishSpeechUi() {
        speechListening = false;
        platformSpeechListening = false;
        offlineSpeechListening = false;
        speechPartialText = "";
        handler.removeCallbacks(speechSilenceTimeout);
        stopSpeechIconAnimation();
        if (binding != null) {
            binding.messageInputLayout.setHint("消息");
            binding.messageInputLayout.setEndIconContentDescription("语音转文字");
        }
    }

    private void beginSpeechUi() {
        speechStopRequested = false;
        speechPartialText = "";
        speechListening = true;
        platformSpeechListening = false;
        offlineSpeechListening = false;
        if (binding != null) {
            binding.messageInputLayout.setHint("消息");
            binding.messageInputLayout.setEndIconContentDescription("停止语音转文字");
            startSpeechIconAnimation();
        }
        markSpeechActivity();
    }

    private void markSpeechActivity() {
        if (!speechListening && !serverSpeechRecording) return;
        handler.removeCallbacks(speechSilenceTimeout);
        handler.postDelayed(speechSilenceTimeout, SPEECH_SILENCE_TIMEOUT_MS);
    }

    private void requestSpeechStop() {
        if (!speechListening) return;
        speechStopRequested = true;
        handler.removeCallbacks(speechSilenceTimeout);
        if (platformSpeechListening && speechEngine != null) {
            try { speechEngine.stopListening(); } catch (RuntimeException ignored) { finishSpeechUi(); }
        } else if (offlineSpeechListening && offlineSpeech != null) {
            try { offlineSpeech.stop(); } catch (RuntimeException ignored) { finishSpeechUi(); }
        } else {
            finishSpeechUi();
        }
        handler.postDelayed(() -> {
            if (!speechListening || !speechStopRequested) return;
            String partial = speechPartialText;
            finishSpeechUi();
            commitSpeechText(partial);
        }, 1400L);
    }

    private void stopSpeechAfterSilence() {
        if (serverSpeechRecording) {
            finishServerSpeechRecording();
            return;
        }
        requestSpeechStop();
    }

    private void startSpeechIconAnimation() {
        if (binding == null) return;
        stopSpeechIconAnimation();
        View icon = speechIconView();
        speechIconAnimator = ObjectAnimator.ofFloat(icon, View.ALPHA, 1f, 0.35f, 1f);
        speechIconAnimator.setDuration(760L);
        speechIconAnimator.setRepeatCount(ValueAnimator.INFINITE);
        speechIconAnimator.start();
    }

    private void stopSpeechIconAnimation() {
        if (speechIconAnimator != null) {
            speechIconAnimator.cancel();
            speechIconAnimator = null;
        }
        if (binding != null) {
            View icon = speechIconView();
            icon.setAlpha(1f);
            icon.setScaleX(1f);
            icon.setScaleY(1f);
        }
    }

    private View speechIconView() {
        if (binding == null) return new View(this);
        View icon = binding.messageInputLayout.findViewById(
            com.google.android.material.R.id.text_input_end_icon);
        return icon == null ? binding.messageInputLayout : icon;
    }

    @SuppressWarnings("deprecation")
    private void startServerSpeechRecording(String notice) {
        if (binding == null || serverSpeechRecording || uploadRequest != null || messageActionRequest != null) return;
        if (ContextCompat.checkSelfPermission(this, android.Manifest.permission.RECORD_AUDIO) != PackageManager.PERMISSION_GRANTED) {
            pendingSpeechPermission = true;
            pendingServerSpeechPermission = true;
            requestPermissions(new String[]{android.Manifest.permission.RECORD_AUDIO}, REQUEST_SPEECH_PERMISSION);
            return;
        }
        try {
            serverSpeechFile = new File(getCacheDir(), "speech_draft_" + System.currentTimeMillis() + ".m4a");
            serverSpeechRecorder = new MediaRecorder();
            serverSpeechRecorder.setAudioSource(MediaRecorder.AudioSource.MIC);
            serverSpeechRecorder.setOutputFormat(MediaRecorder.OutputFormat.MPEG_4);
            serverSpeechRecorder.setAudioEncoder(MediaRecorder.AudioEncoder.AAC);
            serverSpeechRecorder.setAudioEncodingBitRate(96000);
            serverSpeechRecorder.setAudioSamplingRate(44100);
            serverSpeechRecorder.setMaxDuration(60000);
            serverSpeechRecorder.setOutputFile(serverSpeechFile.getAbsolutePath());
            serverSpeechRecorder.setOnInfoListener((recorder, what, extra) -> {
                if (what == MediaRecorder.MEDIA_RECORDER_INFO_MAX_DURATION_REACHED && binding != null) {
                    binding.messageInputLayout.post(this::finishServerSpeechRecording);
                }
            });
            serverSpeechRecorder.prepare();
            serverSpeechRecorder.start();
            serverSpeechStartedAt = System.currentTimeMillis();
            serverSpeechRecording = true;
            beginSpeechUi();
            binding.messageInputLayout.setEndIconContentDescription("完成语音转文字");
            handler.removeCallbacks(serverSpeechTicker);
            handler.post(serverSpeechTicker);
        } catch (RuntimeException | java.io.IOException exception) {
            releaseServerSpeechRecorder();
            if (serverSpeechFile != null) serverSpeechFile.delete();
            finishSpeechUi();
            Snackbar.make(binding.getRoot(), "语音识别录音启动失败，请检查麦克风是否被占用", Snackbar.LENGTH_LONG).show();
        }
    }

    private void finishServerSpeechRecording() {
        if (!serverSpeechRecording || serverSpeechRecorder == null) return;
        long duration = Math.max(0L, System.currentTimeMillis() - serverSpeechStartedAt);
        boolean failed = false;
        try { serverSpeechRecorder.stop(); } catch (RuntimeException exception) { failed = true; }
        releaseServerSpeechRecorder();
        serverSpeechRecording = false;
        handler.removeCallbacks(serverSpeechTicker);
        finishSpeechUi();
        if (failed || duration < 650L || serverSpeechFile == null || !serverSpeechFile.isFile()) {
            if (serverSpeechFile != null) serverSpeechFile.delete();
            Snackbar.make(binding.getRoot(), "录音时间太短，请重新说一次", Snackbar.LENGTH_SHORT).show();
            return;
        }
        uploadServerSpeechDraft(serverSpeechFile);
    }

    private void uploadServerSpeechDraft(File file) {
        binding.progress.setVisibility(View.VISIBLE);
        ContentUriRequestBody requestBody = new ContentUriRequestBody(
            getContentResolver(), Uri.fromFile(file), "audio/mp4", file.length());
        Map<String, String> fields = new LinkedHashMap<>();
        fields.put("scene", "speech_draft");
        fields.put("original_upload", "1");
        uploadRequest = AppAccess.from(this).repository().upload(
            "/api/user/uploads", file.getName(), "audio/mp4", requestBody, fields, result -> {
                uploadRequest = null;
                if (binding == null) return;
                if (!result.isSuccessful()) {
                    binding.progress.setVisibility(View.INVISIBLE);
                    Snackbar.make(binding.getRoot(), result.message().isEmpty() ? "转写录音上传失败" : result.message(), Snackbar.LENGTH_LONG).show();
                    return;
                }
                long uploadId = Jsons.longValue(result.dataObject(), "upload_id");
                if (uploadId <= 0) {
                    binding.progress.setVisibility(View.INVISIBLE);
                    Snackbar.make(binding.getRoot(), "转写录音上传结果不完整", Snackbar.LENGTH_LONG).show();
                    return;
                }
                JsonObject body = new JsonObject();
                body.addProperty("upload_id", uploadId);
                body.addProperty("language", "auto");
                messageActionRequest = AppAccess.from(this).repository().post("/api/user/audio/transcriptions", body, transcriptResult -> {
                    messageActionRequest = null;
                    if (binding == null) return;
                    binding.progress.setVisibility(View.INVISIBLE);
                    if (!transcriptResult.isSuccessful()) {
                        Snackbar.make(binding.getRoot(), friendlySpeechError(transcriptResult.message()),
                            Snackbar.LENGTH_LONG).show();
                        return;
                    }
                    commitSpeechText(Jsons.string(transcriptResult.dataObject(), "transcript"));
                });
            });
    }

    private void releaseServerSpeechRecorder() {
        if (serverSpeechRecorder == null) return;
        try { serverSpeechRecorder.reset(); } catch (RuntimeException ignored) { }
        try { serverSpeechRecorder.release(); } catch (RuntimeException ignored) { }
        serverSpeechRecorder = null;
    }

    private void insertSpeechText(String text) {
        if (binding == null || text == null || text.trim().isEmpty()) return;
        Editable editable = binding.messageInput.getText();
        if (editable == null) return;
        int cursor = Math.max(0, binding.messageInput.getSelectionStart());
        editable.insert(Math.min(cursor, editable.length()), text.trim());
        binding.messageInput.requestFocus();
    }

    private void commitSpeechText(String text) {
        if (text == null || text.trim().isEmpty()) return;
        insertSpeechText(text);
    }

    private void handleSpeechResult(ActivityResult result) {
        finishSpeechUi();
        if (result.getResultCode() != RESULT_OK || result.getData() == null) return;
        ArrayList<String> values = result.getData().getStringArrayListExtra(RecognizerIntent.EXTRA_RESULTS);
        if (values == null || values.isEmpty() || binding == null) return;
        commitSpeechText(values.get(0));
    }

    private boolean handleHoldToTalk(View view, MotionEvent event) {
        switch (event.getActionMasked()) {
            case MotionEvent.ACTION_DOWN:
                voiceTouchStartY = event.getRawY();
                cancelVoiceRecording = false;
                startVoiceRecording();
                return true;
            case MotionEvent.ACTION_MOVE:
                if (voiceRecorder != null) {
                    updateVoiceRecordingUi(Math.max(0f, voiceTouchStartY - event.getRawY()));
                }
                return true;
            case MotionEvent.ACTION_UP:
                finishVoiceRecording(cancelVoiceRecording);
                return true;
            case MotionEvent.ACTION_CANCEL:
                finishVoiceRecording(true);
                return true;
            default:
                return false;
        }
    }

    @SuppressWarnings("deprecation")
    private void startVoiceRecording() {
        if (ContextCompat.checkSelfPermission(this, android.Manifest.permission.RECORD_AUDIO) != PackageManager.PERMISSION_GRANTED) {
            requestPermissions(new String[]{android.Manifest.permission.RECORD_AUDIO}, 4204);
            Snackbar.make(binding.getRoot(), "请允许录音权限后再次按住说话", Snackbar.LENGTH_LONG).show();
            return;
        }
        if (voiceRecorder != null) return;
        try {
            voiceRecordingFile = new File(getCacheDir(), "voice_" + System.currentTimeMillis() + ".m4a");
            voiceRecorder = new MediaRecorder();
            voiceRecorder.setAudioSource(MediaRecorder.AudioSource.MIC);
            voiceRecorder.setOutputFormat(MediaRecorder.OutputFormat.MPEG_4);
            voiceRecorder.setAudioEncoder(MediaRecorder.AudioEncoder.AAC);
            voiceRecorder.setAudioEncodingBitRate(96000);
            voiceRecorder.setAudioSamplingRate(44100);
            voiceRecorder.setMaxDuration(60000);
            voiceRecorder.setOutputFile(voiceRecordingFile.getAbsolutePath());
            voiceRecorder.setOnInfoListener((recorder, what, extra) -> {
                if (what == MediaRecorder.MEDIA_RECORDER_INFO_MAX_DURATION_REACHED && binding != null) {
                    binding.holdToTalkButton.post(() -> finishVoiceRecording(false));
                }
            });
            voiceRecorder.prepare();
            voiceRecorder.start();
            voiceRecordingStartedAt = System.currentTimeMillis();
            binding.voiceRecordingWaveform.setRecordingMode(true);
            binding.voiceRecordingPanel.setVisibility(View.VISIBLE);
            binding.voiceRecordingTime.setText("00:00 / 01:00");
            updateVoiceRecordingUi(0f);
            handler.removeCallbacks(voiceRecordingTicker);
            handler.post(voiceRecordingTicker);
            binding.holdToTalkButton.setPressed(true);
        } catch (RuntimeException | java.io.IOException exception) {
            releaseVoiceRecorder();
            if (voiceRecordingFile != null) voiceRecordingFile.delete();
            Snackbar.make(binding.getRoot(), "录音启动失败，请检查麦克风是否被占用", Snackbar.LENGTH_LONG).show();
        }
    }

    private void finishVoiceRecording(boolean cancel) {
        if (voiceRecorder == null) return;
        long duration = Math.max(0, System.currentTimeMillis() - voiceRecordingStartedAt);
        try { voiceRecorder.stop(); } catch (RuntimeException exception) { cancel = true; }
        releaseVoiceRecorder();
        handler.removeCallbacks(voiceRecordingTicker);
        binding.voiceRecordingWaveform.setRecordingMode(false);
        binding.voiceRecordingPanel.setVisibility(View.GONE);
        binding.holdToTalkButton.setPressed(false);
        binding.holdToTalkButton.setText("按住 说话");
        if (cancel || duration < 650 || voiceRecordingFile == null || !voiceRecordingFile.isFile()) {
            if (voiceRecordingFile != null) voiceRecordingFile.delete();
            Snackbar.make(binding.getRoot(), cancel ? "已取消发送语音" : "说话时间太短", Snackbar.LENGTH_SHORT).show();
            return;
        }
        boolean sendImmediately = pendingAttachments.isEmpty();
        JsonObject voiceMetadata = new JsonObject();
        voiceMetadata.addProperty("audio_kind", "voice");
        pendingAttachments.add(PendingAttachment.local(
            Uri.fromFile(voiceRecordingFile), "audio", voiceRecordingFile.getName(), "audio/mp4",
            voiceRecordingFile.length(), 0, 0, duration, voiceMetadata
        ));
        renderPendingAttachments();
        if (sendImmediately) send();
    }

    private void updateVoiceRecordingUi(float upwardDistance) {
        if (binding == null) return;
        boolean warning = upwardDistance >= dp(36);
        cancelVoiceRecording = upwardDistance >= dp(72);
        int accent = cancelVoiceRecording ? ContextCompat.getColor(this, R.color.error)
            : (warning ? ContextCompat.getColor(this, R.color.warning) : ThemeColors.primary(this));
        int background = cancelVoiceRecording
            ? ContextCompat.getColor(this, R.color.surface_container_high) : ThemeColors.primaryContainer(this);
        String status = cancelVoiceRecording ? "已进入取消区 · 松开取消"
            : (warning ? "继续上滑即可取消" : "松开发送 · 上滑取消");
        binding.voiceRecordingStatus.setText(status);
        binding.voiceRecordingStatus.setTextColor(accent);
        binding.voiceRecordingPanel.setStrokeColor(accent);
        binding.voiceRecordingPanel.setCardBackgroundColor(background);
        binding.holdToTalkButton.setText(cancelVoiceRecording ? "松开 取消" : "松开 发送");
    }

    private void releaseVoiceRecorder() {
        if (voiceRecorder == null) return;
        try { voiceRecorder.reset(); } catch (RuntimeException ignored) { }
        try { voiceRecorder.release(); } catch (RuntimeException ignored) { }
        voiceRecorder = null;
    }

    private void updateComposerActions() {
        if (binding == null) return;
        boolean hasText = binding.messageInput.getText() != null && !binding.messageInput.getText().toString().trim().isEmpty();
        boolean canSend = !voiceMode && attachmentPreparationCount == 0
            && (hasText || !pendingAttachments.isEmpty());
        binding.sendButton.setVisibility(canSend ? View.VISIBLE : View.GONE);
        binding.attachButton.setVisibility(canSend ? View.GONE : View.VISIBLE);
    }

    private void createChatRedPacket() {
        showRedPacketComposer(new ArrayList<>());
    }

    private void showRedPacketComposer(List<JsonObject> recipients) {
        List<JsonObject> selectedRecipients = new ArrayList<>(recipients);
        View sheet = getLayoutInflater().inflate(R.layout.bottom_sheet_chat_transaction, null, false);
        BottomSheetDialog dialog = new BottomSheetDialog(this);
        dialog.setContentView(sheet);

        ImageView icon = sheet.findViewById(R.id.typeIcon);
        TextView title = sheet.findViewById(R.id.titleText);
        TextView subtitle = sheet.findViewById(R.id.subtitleText);
        TextView recipient = sheet.findViewById(R.id.recipientText);
        MaterialCheckBox includeSelf = sheet.findViewById(R.id.includeSelfCheck);
        View redPacketOptions = sheet.findViewById(R.id.redPacketOptionsContainer);
        MaterialButtonToggleGroup distributionToggle = sheet.findViewById(R.id.distributionModeToggle);
        MaterialButtonToggleGroup eligibilityToggle = sheet.findViewById(R.id.eligibilityModeToggle);
        TextInputLayout countLayout = sheet.findViewById(R.id.redPacketCountLayout);
        TextInputEditText countInput = sheet.findViewById(R.id.redPacketCountInput);
        TextInputLayout valueLayout = sheet.findViewById(R.id.valueLayout);
        TextInputEditText valueInput = sheet.findViewById(R.id.valueInput);
        TextInputLayout messageLayout = sheet.findViewById(R.id.messageLayout);
        TextInputEditText messageInput = sheet.findViewById(R.id.messageInput);
        TextView rule = sheet.findViewById(R.id.ruleText);
        MaterialButton cancel = sheet.findViewById(R.id.cancelButton);
        MaterialButton confirm = sheet.findViewById(R.id.confirmButton);

        icon.setImageResource(R.drawable.ic_wallet);
        title.setText("发红包");
        subtitle.setText("发放份数与领取资格分别设置");
        redPacketOptions.setVisibility(View.VISIBLE);
        distributionToggle.check(R.id.distributionCountSplit);
        eligibilityToggle.check(R.id.eligibilityContextAll);
        countInput.setText("1");
        includeSelf.setVisibility(View.VISIBLE);
        includeSelf.setChecked(true);
        Runnable updateRecipients = () -> {
            boolean selectedOnly = eligibilityToggle.getCheckedButtonId() == R.id.eligibilitySelected;
            List<JsonObject> active = redPacketRecipients(selectedRecipients, includeSelf.isChecked());
            recipient.setEnabled(selectedOnly);
            recipient.setAlpha(selectedOnly ? 1f : 0.64f);
            if (!selectedOnly) {
                recipient.setText(includeSelf.isChecked()
                    ? "领取范围：当前会话所有人（包含自己）"
                    : "领取范围：当前会话所有其他人");
            } else {
                recipient.setText(active.isEmpty()
                    ? "领取范围：点击选择指定人员"
                    : "指定人员：" + recipientNames(active) + " · 点击修改");
            }
        };
        recipient.setOnClickListener(view -> chooseRecipients(
            "选择可领取人员（最多 100 人）",
            includeSelf.isChecked() ? 99 : 100,
            values -> {
                selectedRecipients.clear();
                selectedRecipients.addAll(values);
                updateRecipients.run();
            }
        ));
        includeSelf.setOnCheckedChangeListener((button, checked) -> {
            if (checked && selectedRecipients.size() >= 100) {
                button.setChecked(false);
                Snackbar.make(sheet, "指定人员总数最多 100 人，请先减少一位好友", Snackbar.LENGTH_LONG).show();
                return;
            }
            updateRecipients.run();
        });
        eligibilityToggle.addOnButtonCheckedListener((group, checkedId, isChecked) -> {
            if (isChecked) updateRecipients.run();
        });
        Runnable updateDistribution = () -> {
            boolean singleRace = distributionToggle.getCheckedButtonId() == R.id.distributionSingleRace;
            countLayout.setVisibility(singleRace ? View.GONE : View.VISIBLE);
            rule.setText(singleRace
                ? "一份随机抢：首位成功领取的人获得全部余额，其他参与者无法再领取。24 小时后无人领取会自动退回。"
                : "按份数发：余额随机拆成指定份数，先领取者先得；每份最低 0.01 余额，24 小时后未领余额自动退回。");
        };
        distributionToggle.addOnButtonCheckedListener((group, checkedId, isChecked) -> {
            if (isChecked) updateDistribution.run();
        });
        updateRecipients.run();
        updateDistribution.run();
        valueLayout.setHint("红包总余额");
        valueInput.setInputType(android.text.InputType.TYPE_CLASS_NUMBER
            | android.text.InputType.TYPE_NUMBER_FLAG_DECIMAL);
        messageLayout.setHint("祝福语");
        messageInput.setText("恭喜发财");
        messageInput.setSelection(messageInput.length());
        confirm.setText("发送红包");
        cancel.setOnClickListener(view -> dialog.dismiss());
        confirm.setOnClickListener(view -> {
            boolean selectedOnly = eligibilityToggle.getCheckedButtonId() == R.id.eligibilitySelected;
            boolean singleRace = distributionToggle.getCheckedButtonId() == R.id.distributionSingleRace;
            List<JsonObject> activeRecipients = redPacketRecipients(selectedRecipients, includeSelf.isChecked());
            if (selectedOnly && activeRecipients.isEmpty()) {
                Snackbar.make(sheet, "请至少选择一位指定人员，或允许自己参与", Snackbar.LENGTH_LONG).show();
                return;
            }
            if (selectedOnly && activeRecipients.size() > 100) {
                Snackbar.make(sheet, "指定人员总数最多 100 人", Snackbar.LENGTH_LONG).show();
                return;
            }
            int totalCount = 1;
            if (!singleRace) {
                try {
                    totalCount = Integer.parseInt(inputText(countInput));
                } catch (NumberFormatException ignored) {
                    totalCount = 0;
                }
                if (totalCount <= 0) {
                    countLayout.setError("请输入大于 0 的红包份数");
                    countInput.requestFocus();
                    return;
                }
                if (selectedOnly && totalCount > activeRecipients.size()) {
                    countLayout.setError("红包份数不能超过可领取人数 " + activeRecipients.size());
                    countInput.requestFocus();
                    return;
                }
            }
            countLayout.setError(null);
            BigDecimal amount = moneyValue(valueInput);
            if (amount == null || amount.signum() <= 0) {
                valueLayout.setError("请输入大于 0 且最多两位小数的红包余额");
                valueInput.requestFocus();
                return;
            }
            BigDecimal minimum = new BigDecimal(totalCount).movePointLeft(2);
            if (amount.compareTo(minimum) < 0) {
                valueLayout.setError("当前 " + totalCount + " 份，红包总额不能少于 "
                    + minimum.setScale(2, RoundingMode.UNNECESSARY).toPlainString());
                valueInput.requestFocus();
                return;
            }
            valueLayout.setError(null);
            String greeting = inputText(messageInput);
            if (greeting.isEmpty()) greeting = "恭喜发财";
            JsonObject body = new JsonObject();
            if (selectedOnly) {
                JsonArray ids = new JsonArray();
                for (JsonObject item : activeRecipients) ids.add(Jsons.longValue(item, "user_id"));
                body.add("to_user_ids", ids);
            }
            body.addProperty("total_amount", amount.toPlainString());
            body.addProperty("total_count", totalCount);
            body.addProperty("packet_type", "random");
            body.addProperty("packet_label", singleRace ? "一份随机抢" : "按份数发");
            body.addProperty("distribution_mode", singleRace ? "single_race" : "count_split");
            body.addProperty("eligibility_mode", selectedOnly ? "selected" : "context_all");
            body.addProperty("include_sender", includeSelf.isChecked());
            body.addProperty("delivery_scope", redPacketDeliveryScope());
            body.addProperty("context_id", searchTargetId());
            body.addProperty("context_user_id", resolvedConversationPeerId());
            body.addProperty("expire_seconds", 86400);
            body.addProperty("message", greeting);
            final int sentCount = totalCount;
            final boolean sentSingleRace = singleRace;
            final boolean sentSelectedOnly = selectedOnly;
            dialog.dismiss();
            postQuickAction("/api/user/red-packets", body, "红包创建失败", result -> {
                long packetId = Jsons.longValue(result, "packet_id");
                JsonObject metadata = result.deepCopy();
                metadata.addProperty("packet_id", packetId);
                metadata.addProperty("message", Jsons.string(body, "message"));
                metadata.addProperty("total_amount", amount.toPlainString());
                metadata.addProperty("total_count", sentCount);
                metadata.addProperty("packet_type", "random");
                metadata.addProperty("packet_label", sentSingleRace ? "一份随机抢" : "按份数发");
                metadata.addProperty("distribution_mode", sentSingleRace ? "single_race" : "count_split");
                metadata.addProperty("distribution_label", sentSingleRace ? "一份随机抢" : "按份数发");
                metadata.addProperty("eligibility_mode", sentSelectedOnly ? "selected" : "context_all");
                metadata.addProperty("eligibility_label", sentSelectedOnly ? "仅指定人员" : "当前会话所有人");
                if (sentSelectedOnly) metadata.add("recipients", recipientSummary(activeRecipients));
                metadata.addProperty("status", "open");
                sendBusinessAttachment("red_packet", Jsons.string(body, "message"),
                    "/api/user/red-packets/" + packetId, metadata, "红包");
            });
        });
        RuntimeLanguage.applyTree(this, sheet);
        GlassBottomSheet.prepare(dialog, this, 0.90f, false);
        dialog.show();
    }

    private void createChatTransfer() {
        chooseRecipients("选择收款人（最多 10 人）", recipients -> {
            ActionSpec action = ActionSpec.builder("填写转账信息", "POST", "/api/user/transfers")
                .fields(
                    FieldSpec.typed("amount", "每人转账余额", FieldType.DECIMAL, true),
                    FieldSpec.of("message", "转账说明").withDefault("转账给你")
                ).build();
            DynamicFormDialog.show(this, action, null, body -> {
                JsonArray ids = new JsonArray();
                for (JsonObject recipient : recipients) ids.add(Jsons.longValue(recipient, "user_id"));
                body.add("to_user_ids", ids);
                body.addProperty("expire_seconds", 86400);
                postQuickAction(action.pathTemplate(), body, "转账失败", result -> {
                    JsonObject metadata = result.deepCopy();
                    metadata.addProperty("amount", Jsons.string(body, "amount"));
                    metadata.addProperty("message", Jsons.string(body, "message"));
                    metadata.add("recipients", recipientSummary(recipients));
                    long transferId = Jsons.longValue(result, "transfer_id");
                    sendBusinessAttachment("transfer", "待确认转账",
                        "/api/user/transfers/" + transferId, metadata, "转账");
                });
            });
        });
    }

    private void chooseContactCard() {
        if (messageActionRequest != null || binding == null) return;
        binding.progress.setVisibility(View.VISIBLE);
        messageActionRequest = AppAccess.from(this).repository().get(
            AppAccess.from(this).session().role().mePath(), new LinkedHashMap<>(), result -> {
                messageActionRequest = null;
                if (binding == null || isFinishing() || isDestroyed()) return;
                binding.progress.setVisibility(View.INVISIBLE);
                JsonObject profile = Jsons.object(result.dataObject(), "user");
                if (profile.size() == 0) profile = result.dataObject();
                confirmOwnContactCard(profile);
            });
    }

    private void confirmOwnContactCard(JsonObject profile) {
        if (binding == null || isFinishing() || isDestroyed()) return;
        JsonObject metadata = ContactCardIdentity.metadata(
            profile,
            AppAccess.from(this).session().actorId(),
            AppAccess.from(this).session().account(),
            true
        );
        String target = contactCardTargetLabel() + " · " + emptyFallback(normalTitle, "当前会话");
        View sheet = getLayoutInflater().inflate(R.layout.bottom_sheet_contact_card_confirm, null, false);
        BottomSheetDialog dialog = new BottomSheetDialog(this);
        dialog.setContentView(sheet);
        RuntimeLanguage.applyTree(this, sheet);
        ImageView avatar = sheet.findViewById(R.id.cardAvatar);
        TextView displayName = sheet.findViewById(R.id.cardDisplayName);
        TextView accountText = sheet.findViewById(R.id.cardAccount);
        TextView uidText = sheet.findViewById(R.id.cardUid);
        TextView targetText = sheet.findViewById(R.id.cardTarget);
        MaterialButton cancel = sheet.findViewById(R.id.cardCancelButton);
        MaterialButton confirm = sheet.findViewById(R.id.cardConfirmButton);

        String account = Jsons.string(metadata, "account");
        String uid = Jsons.string(metadata, "uid");
        displayName.setText(emptyFallback(ContactCardIdentity.displayName(metadata), "我的名片"));
        accountText.setText(RuntimeLanguage.translate(this, "账号") + "：" + emptyFallback(account, "-"));
        uidText.setText("UID：" + uid);
        targetText.setText(target);
        String avatarUrl = ImageLoader.get().absoluteUrl(this, Jsons.string(metadata, "avatar"));
        ImageLoader.get().load(avatarUrl, avatar, R.drawable.ic_person);
        cancel.setOnClickListener(view -> dialog.dismiss());
        confirm.setOnClickListener(view -> {
            confirm.setEnabled(false);
            dialog.dismiss();
            sendOwnContactCard(metadata);
        });
        GlassBottomSheet.prepare(dialog, this, 0.72f, false);
        dialog.show();
    }

    private String contactCardTargetLabel() {
        switch (mode()) {
            case MODE_CONVERSATION:
                return RuntimeLanguage.translate(this, "私聊").toString();
            case MODE_ROOM:
                return roomEntityLabel();
            case MODE_SERVICE_ADMIN:
            case MODE_SERVICE_USER:
                return RuntimeLanguage.translate(this, "客服").toString();
            default:
                return RuntimeLanguage.translate(this, "聊天").toString();
        }
    }

    private void sendOwnContactCard(JsonObject metadata) {
        long userId = Jsons.longValue(metadata, "user_id");
        String displayName = ContactCardIdentity.displayName(metadata);
        sendBusinessAttachment("contact_card", displayName,
            "/api/user/profiles/" + userId, metadata, "名片");
    }

    private void chooseChatGift() {
        if (messageActionRequest != null) return;
        binding.progress.setVisibility(View.VISIBLE);
        messageActionRequest = AppAccess.from(this).repository().get("/api/user/gift-catalog", new LinkedHashMap<>(), result -> {
            messageActionRequest = null;
            if (binding == null) return;
            binding.progress.setVisibility(View.INVISIBLE);
            if (!result.isSuccessful()) {
                Snackbar.make(binding.getRoot(), result.message().isEmpty() ? "礼物列表加载失败" : result.message(), Snackbar.LENGTH_LONG).show();
                return;
            }
            List<JsonObject> gifts = result.objectItems();
            if (gifts.isEmpty()) {
                Snackbar.make(binding.getRoot(), "当前没有可赠送的礼物", Snackbar.LENGTH_LONG).show();
                return;
            }
            showGiftCatalog(gifts);
        });
    }

    private void showGiftCatalog(List<JsonObject> gifts) {
        View sheet = getLayoutInflater().inflate(R.layout.bottom_sheet_chat_gift_catalog, null, false);
        BottomSheetDialog dialog = new BottomSheetDialog(this);
        dialog.setContentView(sheet);
        LinearLayout catalog = sheet.findViewById(R.id.giftCatalogList);
        for (JsonObject gift : gifts) {
            View item = getLayoutInflater().inflate(R.layout.item_chat_gift_catalog, catalog, false);
            TextView name = item.findViewById(R.id.giftName);
            TextView description = item.findViewById(R.id.giftDescription);
            TextView price = item.findViewById(R.id.giftPrice);
            name.setText(valueOr(firstNonEmpty(gift, "gift_name", "name"), "未命名礼物"));
            description.setText(valueOr(firstNonEmpty(gift, "description", "subtitle"), "选择后可发送给一位或多位好友"));
            price.setText("余额 " + valueOr(firstNonEmpty(gift, "price", "balance"), "0"));
            LinearLayout.LayoutParams params = new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT);
            params.bottomMargin = dp(8);
            catalog.addView(item, params);
            item.setOnClickListener(view -> {
                dialog.dismiss();
                chooseRecipients("选择收礼人（最多 10 人）",
                    recipients -> showGiftComposer(gift, recipients));
            });
        }
        sheet.findViewById(R.id.cancelButton).setOnClickListener(view -> dialog.dismiss());
        RuntimeLanguage.applyTree(this, sheet);
        GlassBottomSheet.prepare(dialog, this, 0.84f, false);
        dialog.show();
    }

    private void showGiftComposer(JsonObject gift, List<JsonObject> recipients) {
        View sheet = getLayoutInflater().inflate(R.layout.bottom_sheet_chat_transaction, null, false);
        BottomSheetDialog dialog = new BottomSheetDialog(this);
        dialog.setContentView(sheet);

        ImageView icon = sheet.findViewById(R.id.typeIcon);
        TextView title = sheet.findViewById(R.id.titleText);
        TextView subtitle = sheet.findViewById(R.id.subtitleText);
        TextView recipient = sheet.findViewById(R.id.recipientText);
        TextInputLayout valueLayout = sheet.findViewById(R.id.valueLayout);
        TextInputEditText valueInput = sheet.findViewById(R.id.valueInput);
        TextInputLayout messageLayout = sheet.findViewById(R.id.messageLayout);
        TextInputEditText messageInput = sheet.findViewById(R.id.messageInput);
        TextView rule = sheet.findViewById(R.id.ruleText);
        MaterialButton cancel = sheet.findViewById(R.id.cancelButton);
        MaterialButton confirm = sheet.findViewById(R.id.confirmButton);

        String giftName = Jsons.string(gift, "gift_name");
        icon.setImageResource(R.drawable.ic_gift);
        title.setText("赠送礼物");
        subtitle.setText(giftName + " · 单价余额 " + Jsons.string(gift, "price"));
        recipient.setText("收礼人：" + recipientNames(recipients));
        valueLayout.setHint("每人礼物数量");
        valueInput.setInputType(android.text.InputType.TYPE_CLASS_NUMBER);
        valueInput.setText("1");
        valueInput.setSelection(valueInput.length());
        messageLayout.setHint("祝福语");
        messageInput.setText("送你一份礼物");
        messageInput.setSelection(messageInput.length());
        rule.setText("礼物将在 24 小时后过期，未领取的礼物会自动退回。");
        confirm.setText("发送礼物");
        cancel.setOnClickListener(view -> dialog.dismiss());
        confirm.setOnClickListener(view -> {
            long quantity = positiveLong(valueInput);
            if (quantity <= 0L) {
                valueLayout.setError("请输入大于 0 的礼物数量");
                valueInput.requestFocus();
                return;
            }
            valueLayout.setError(null);
            String greeting = inputText(messageInput);
            if (greeting.isEmpty()) greeting = "送你一份礼物";
            JsonObject body = new JsonObject();
            body.addProperty("gift_id", Jsons.longValue(gift, "id"));
            JsonArray ids = new JsonArray();
            for (JsonObject item : recipients) ids.add(Jsons.longValue(item, "user_id"));
            body.add("to_user_ids", ids);
            body.addProperty("expire_seconds", 86400);
            body.addProperty("quantity", quantity);
            body.addProperty("message", greeting);
            dialog.dismiss();
            postQuickAction("/api/user/gifts", body, "礼物发送失败", result -> {
                JsonObject metadata = result.deepCopy();
                metadata.add("gift", gift.deepCopy());
                metadata.addProperty("quantity", quantity);
                metadata.addProperty("message", Jsons.string(body, "message"));
                metadata.add("recipients", recipientSummary(recipients));
                long giftRecordId = Jsons.longValue(result, "gift_record_id");
                sendBusinessAttachment("gift", Jsons.string(gift, "gift_name"),
                    "/api/user/gifts/" + giftRecordId, metadata, "礼物");
            });
        });
        RuntimeLanguage.applyTree(this, sheet);
        GlassBottomSheet.prepare(dialog, this, 0.90f, false);
        dialog.show();
    }

    private interface RecipientResult { void onSelected(List<JsonObject> recipients); }

    private void chooseRecipients(String title, RecipientResult callback) {
        chooseRecipients(title, 10, callback);
    }

    private void chooseRecipients(String title, int maxSelection, RecipientResult callback) {
        if (pendingRecipientResult != null) return;
        pendingRecipientResult = callback;
        recipientPicker.launch(SocialDirectoryActivity.pickFriendsIntent(
            this,
            Math.max(1, Math.min(500, maxSelection)),
            title,
            new long[]{AppAccess.from(this).session().actorId()},
            "不能选择自己"
        ));
    }

    private List<JsonObject> redPacketRecipients(List<JsonObject> recipients, boolean includeSelf) {
        LinkedHashMap<Long, JsonObject> unique = new LinkedHashMap<>();
        for (JsonObject recipient : recipients) {
            long userId = Jsons.longValue(recipient, "user_id");
            if (userId > 0L) unique.put(userId, recipient);
        }
        if (includeSelf) {
            long selfId = AppAccess.from(this).session().actorId();
            JsonObject self = new JsonObject();
            self.addProperty("user_id", selfId);
            self.addProperty("account", AppAccess.from(this).session().account());
            self.addProperty("nickname", "我自己");
            self.addProperty("remark", "我自己");
            self.addProperty("uid", String.valueOf(selfId));
            self.addProperty("is_self", true);
            unique.put(selfId, self);
        }
        return new ArrayList<>(unique.values());
    }

    private JsonArray recipientSummary(List<JsonObject> recipients) {
        JsonArray values = new JsonArray();
        for (JsonObject recipient : recipients) {
            JsonObject item = new JsonObject();
            item.addProperty("user_id", Jsons.longValue(recipient, "user_id"));
            item.addProperty("name", first(recipient, "remark", "nickname", "account"));
            item.addProperty("uid", first(recipient, "uid", "account"));
            values.add(item);
        }
        return values;
    }

    private String recipientNames(List<JsonObject> recipients) {
        StringBuilder names = new StringBuilder();
        int visibleCount = Math.min(3, recipients.size());
        for (int index = 0; index < visibleCount; index++) {
            if (names.length() > 0) names.append('、');
            String name = first(recipients.get(index), "remark", "nickname", "account", "uid");
            names.append(name.isEmpty() ? "未命名用户" : name);
        }
        if (recipients.size() > visibleCount) names.append(" 等 ").append(recipients.size()).append(" 人");
        else names.append("（").append(recipients.size()).append(" 人）");
        return names.toString();
    }

    private static String inputText(TextInputEditText input) {
        return input.getText() == null ? "" : input.getText().toString().trim();
    }

    private static long positiveLong(TextInputEditText input) {
        try {
            return Long.parseLong(inputText(input));
        } catch (NumberFormatException ignored) {
            return 0L;
        }
    }

    private static BigDecimal moneyValue(TextInputEditText input) {
        String raw = inputText(input);
        if (!raw.matches("\\d+(?:\\.\\d{1,2})?")) return null;
        try {
            BigDecimal value = new BigDecimal(raw);
            if (value.scale() > 2) return null;
            return value.setScale(2, RoundingMode.UNNECESSARY);
        } catch (NumberFormatException | ArithmeticException ignored) {
            return null;
        }
    }

    private interface QuickActionResult { void onResult(JsonObject data); }

    private void postQuickAction(String path, JsonObject body, String fallback, QuickActionResult callback) {
        if (messageActionRequest != null) return;
        binding.progress.setVisibility(View.VISIBLE);
        messageActionRequest = AppAccess.from(this).repository().post(path, body, result -> {
            messageActionRequest = null;
            if (binding == null) return;
            binding.progress.setVisibility(View.INVISIBLE);
            if (!result.isSuccessful()) {
                Snackbar.make(binding.getRoot(), result.message().isEmpty() ? fallback : result.message(), Snackbar.LENGTH_LONG).show();
                return;
            }
            callback.onResult(result.dataObject());
        });
    }

    private void sendBusinessAttachment(
        String mediaType,
        String name,
        String url,
        JsonObject metadata,
        String tag
    ) {
        if (sendRequest != null || uploadRequest != null) {
            Snackbar.make(binding.getRoot(), "上一条内容仍在发送，请稍候", Snackbar.LENGTH_SHORT).show();
            return;
        }
        dismissRecentSuggestion();
        hideMediaPanel();
        JsonObject attachment = new JsonObject();
        attachment.addProperty("media_type", mediaType);
        attachment.addProperty("url", url == null || url.isEmpty() ? "/api/user/me" : url);
        if (name != null && !name.isEmpty()) attachment.addProperty("file_name", name);
        attachment.add("metadata", metadata == null ? new JsonObject() : metadata.deepCopy());
        JsonArray attachments = new JsonArray();
        attachments.add(attachment);
        JsonObject body = new JsonObject();
        body.addProperty("content", "");
        body.add("attachments", attachments);
        JsonArray tags = new JsonArray();
        tags.add(tag);
        body.add("tags", tags);
        if (MODE_CONVERSATION.equals(mode())) body.addProperty("to_user_id", resolvedConversationPeerId());
        setComposerEnabled(false);
        binding.progress.setVisibility(View.VISIBLE);
        sendRequest = AppAccess.from(this).repository().post(writePath(), body, result -> {
            sendRequest = null;
            if (binding == null) return;
            binding.progress.setVisibility(View.INVISIBLE);
            setComposerEnabled(true);
            if (result.isAuthenticationFailure()) { login(); return; }
            if (!result.isSuccessful()) {
                Snackbar.make(binding.getRoot(), result.message().isEmpty() ? "发送失败" : result.message(), Snackbar.LENGTH_LONG).show();
                return;
            }
            adoptCreatedConversation(result.dataObject());
            refreshMessagesNow();
        });
    }

    private void handleLocationPickerResult(androidx.activity.result.ActivityResult result) {
        if (result.getResultCode() != RESULT_OK || result.getData() == null || binding == null) return;
        Intent data = result.getData();
        String name = data.getStringExtra(LocationPickerActivity.EXTRA_LOCATION_NAME);
        String address = data.getStringExtra(LocationPickerActivity.EXTRA_ADDRESS);
        double latitude = data.getDoubleExtra(LocationPickerActivity.EXTRA_LATITUDE, Double.NaN);
        double longitude = data.getDoubleExtra(LocationPickerActivity.EXTRA_LONGITUDE, Double.NaN);
        if (name == null || name.trim().isEmpty()) {
            Snackbar.make(binding.getRoot(), "地点名称为空，请重新选择", Snackbar.LENGTH_LONG).show();
            return;
        }
        JsonObject metadata = new JsonObject();
        metadata.addProperty("location_name", name.trim());
        metadata.addProperty("address", address == null ? "" : address.trim());
        if (!Double.isNaN(latitude) && !Double.isNaN(longitude)) {
            metadata.addProperty("latitude", latitude);
            metadata.addProperty("longitude", longitude);
        }
        sendBusinessAttachment("location", name.trim(), "/api/user/me", metadata, "位置");
    }

    private void openBusinessAttachment(JsonObject attachment) {
        String type = Jsons.string(attachment, "media_type");
        JsonObject metadata = Jsons.object(attachment, "metadata");
        if ("location".equals(type)) {
            double latitude = doubleValue(metadata, "latitude", Double.NaN);
            double longitude = doubleValue(metadata, "longitude", Double.NaN);
            LocationPickerActivity.openPreview(this,
                Jsons.string(metadata, "location_name"), Jsons.string(metadata, "address"),
                latitude, longitude);
            return;
        }
        if ("contact_card".equals(type)) {
            long userId = Jsons.longValue(metadata, "user_id");
            if (userId > 0) UserProfileActivity.open(this, userId);
            return;
        }
        if ("moment_share".equals(type) || ("favorite".equals(type) && isMomentAttachment(metadata))) {
            openMomentAttachment(metadata);
            return;
        }
        if ("favorite".equals(type)) {
            openFavoriteAttachment(metadata);
            return;
        }
        long id;
        String path;
        String title;
        if ("red_packet".equals(type)) {
            id = Jsons.longValue(metadata, "packet_id");
            path = "/api/user/red-packets/" + id;
            title = "红包详情";
        } else if ("transfer".equals(type)) {
            id = Jsons.longValue(metadata, "transfer_id");
            if (id <= 0) id = firstItemId(metadata, "items");
            path = "/api/user/transfers/" + id;
            title = "转账详情";
        } else if ("gift".equals(type)) {
            id = Jsons.longValue(metadata, "gift_record_id");
            if (id <= 0) id = firstItemId(metadata, "items");
            path = "/api/user/gifts/" + id;
            title = "礼物详情";
        } else {
            return;
        }
        if (id <= 0) {
            Snackbar.make(binding.getRoot(), "详情编号缺失，无法打开", Snackbar.LENGTH_LONG).show();
            return;
        }
        loadBusinessDetail(type, path, title);
    }

    private long firstItemId(JsonObject value, String key) {
        JsonArray items = Jsons.array(value, key);
        return items.isEmpty() || !items.get(0).isJsonObject() ? 0 : Jsons.longValue(items.get(0).getAsJsonObject(), "id");
    }

    private void openFavoriteAttachment(JsonObject item) {
        String type = Jsons.string(item, "favorite_type");
        if ("moment".equals(type) || "moment".equals(Jsons.string(item, "content_kind"))) {
            openMomentAttachment(item);
            return;
        }
        if ("post".equals(type)) {
            ForumPostActivity.open(this, Jsons.longValue(item, "target_id"));
            return;
        }
        if ("note".equals(type)) {
            long documentId = Jsons.longValue(item, "document_id");
            if (documentId <= 0) documentId = Jsons.longValue(item, "target_id");
            if (documentId > 0) {
                startActivity(new Intent(this, DocumentEditorActivity.class)
                    .putExtra(DocumentEditorActivity.EXTRA_DOCUMENT_ID, documentId));
            } else {
                RecordDetailDialog.show(this, "笔记收藏快照", FavoriteCenterActivity.displayItem(item));
            }
            return;
        }
        if ("upload".equals(type)) {
            JsonObject file = item.deepCopy();
            file.addProperty("id", Jsons.longValue(item, "target_id"));
            file.addProperty("original_name", Jsons.string(item, "title"));
            file.addProperty("file_url", Jsons.string(item, "preview_url"));
            FilePreviewActivity.open(this, file);
            return;
        }
        if ("resource".equals(type)) {
            ResourceHallActivity.openResource(this, Jsons.longValue(item, "target_id"));
            return;
        }
        if ("app".equals(type)) {
            ResourceHallActivity.openApp(this, Jsons.longValue(item, "target_id"));
            return;
        }
        if ("goods".equals(type)) {
            startActivity(MainActivity.moduleIntent(this, "shop_goods", Jsons.longValue(item, "target_id")));
            return;
        }
        if ("bounty".equals(type)) {
            startActivity(MainActivity.moduleIntent(this, "bounties", Jsons.longValue(item, "target_id")));
            return;
        }
        if ("message".equals(type)) {
            String scope = Jsons.string(item, "scope_type");
            long scopeId = Jsons.longValue(item, "scope_id");
            Intent intent;
            if ("group".equals(scope)) {
                intent = roomIntent(this, scopeId, Jsons.string(item, "scope_name"));
            } else if ("service".equals(scope)) {
                intent = userServiceIntent(this);
            } else {
                intent = conversationIntent(
                    this,
                    scopeId,
                    Jsons.longValue(item, "peer_user_id"),
                    first(item, "peer_name", "scope_name", "peer_account", "title"));
            }
            long messageId = Jsons.longValue(item, "message_id");
            if (messageId <= 0) messageId = Jsons.longValue(item, "target_id");
            if (messageId > 0) focusMessage(intent, messageId);
            startActivity(intent);
            return;
        }
        RecordDetailDialog.show(this, "收藏详情", FavoriteCenterActivity.displayItem(item));
    }

    private void openMomentAttachment(JsonObject item) {
        long momentId = Jsons.longValue(item, "moment_id");
        if (momentId <= 0) momentId = Jsons.longValue(item, "target_id");
        long userId = Jsons.longValue(item, "author_user_id");
        String author = first(item, "author_name", "display_name", "nickname", "account");
        if (momentId > 0) {
            MomentTimelineActivity.openMoment(this, momentId, userId, author);
            return;
        }
        if (userId > 0) {
            MomentTimelineActivity.openForUser(this, userId, author);
            return;
        }
        Snackbar.make(binding.getRoot(), "动态信息不完整，暂时无法打开", Snackbar.LENGTH_LONG).show();
    }

    private void loadBusinessDetail(String type, String path, String title) {
        if (messageActionRequest != null) return;
        binding.progress.setVisibility(View.VISIBLE);
        messageActionRequest = AppAccess.from(this).repository().get(path, new LinkedHashMap<>(), result -> {
            messageActionRequest = null;
            if (binding == null) return;
            binding.progress.setVisibility(View.INVISIBLE);
            if (!result.isSuccessful()) {
                Snackbar.make(binding.getRoot(), result.message().isEmpty() ? "详情加载失败" : result.message(), Snackbar.LENGTH_LONG).show();
                return;
            }
            JsonObject item = Jsons.object(result.dataObject(), "item");
            applyBusinessStateToMessages(type, item);
            showBusinessDetailSheet(type, title, path, item);
        });
    }

    private void confirmRedPacketReturn(Runnable action) {
        if (binding == null || isFinishing() || isDestroyed()) return;
        JsonObject detail = new JsonObject();
        detail.addProperty("操作", "将你的未领取份额退回给发送人");
        detail.addProperty("结果", "退回后不能再次领取这一份红包");
        RecordDetailDialog.showDecision(
            this,
            "确认退回红包",
            detail,
            "取消",
            null,
            "确认退回",
            action
        );
    }

    private boolean isMomentAttachment(JsonObject metadata) {
        if (metadata == null) return false;
        return "moment".equalsIgnoreCase(Jsons.string(metadata, "favorite_type"))
            || "moment".equalsIgnoreCase(Jsons.string(metadata, "content_kind"));
    }

    private void showBusinessDetailSheet(String type, String title, String path, JsonObject item) {
        if (isFinishing() || isDestroyed() || binding == null) return;
        BottomSheetDialog dialog = new BottomSheetDialog(this);
        View content = getLayoutInflater().inflate(R.layout.bottom_sheet_chat_business_detail, null, false);
        dialog.setContentView(content);

        ImageView icon = content.findViewById(R.id.detailIcon);
        TextView titleView = content.findViewById(R.id.detailTitle);
        TextView statusView = content.findViewById(R.id.detailStatus);
        TextView amountView = content.findViewById(R.id.detailAmount);
        TextView summaryView = content.findViewById(R.id.detailSummary);
        LinearProgressIndicator progress = content.findViewById(R.id.detailProgress);
        TextView progressText = content.findViewById(R.id.detailProgressText);
        TextView recordsLabel = content.findViewById(R.id.detailRecordsLabel);
        ScrollView recordsScroll = content.findViewById(R.id.detailRecordsScroll);
        LinearLayout records = content.findViewById(R.id.detailRecords);
        MaterialButton close = content.findViewById(R.id.detailCloseButton);
        MaterialButton refund = content.findViewById(R.id.detailRefundButton);
        MaterialButton primary = content.findViewById(R.id.detailPrimaryButton);

        String state = currentBusinessState(type, item);
        titleView.setText(title);
        statusView.setText(currentBusinessStateLabel(type, state));
        statusView.setTextColor(businessStateIsFinal(state)
            ? getColor(R.color.on_surface_variant) : ThemeColors.primary(this));
        close.setOnClickListener(view -> dialog.dismiss());

        if ("red_packet".equals(type)) {
            icon.setImageResource(R.drawable.ic_red_packet);
            amountView.setText("余额 " + normalizedAmount(Jsons.string(item, "total_amount")));
            String creator = first(item, "creator_name", "creator_account");
            String message = Jsons.string(item, "message");
            String packetLabel = emptyFallback(Jsons.string(item, "packet_label"),
                "equal".equals(Jsons.string(item, "packet_type")) ? "等额红包" : "拼手气红包");
            summaryView.setText((creator.isEmpty() ? "用户" : creator) + " 发出的" + packetLabel
                + (message.isEmpty() ? "" : " · " + message));
            long total = Math.max(0L, Jsons.longValue(item, "total_count"));
            long remain = Math.max(0L, Jsons.longValue(item, "remain_count"));
            long processed = Math.max(0L, total - remain);
            JsonArray claims = Jsons.array(item, "claims");
            JsonArray returns = Jsons.array(item, "returns");
            int percent = total <= 0L ? 0 : (int) Math.min(100L, processed * 100L / total);
            progress.setProgressCompat(percent, false);
            progressText.setText("已处理 " + processed + "/" + total
                + " · 领取 " + claims.size() + " · 退回 " + returns.size());
            recordsLabel.setText("领取与退回记录 · " + packetLabel);
            for (JsonElement element : claims) {
                if (!element.isJsonObject()) continue;
                JsonObject claim = element.getAsJsonObject();
                String luckiest = boolValue(claim, "is_luckiest") ? " · 运气王" : "";
                addBusinessRecordRow(records, first(claim, "nickname", "account"),
                    "领取 · 余额 " + normalizedAmount(Jsons.string(claim, "amount"))
                        + luckiest + readableTimeSuffix(Jsons.string(claim, "created_at")));
            }
            for (JsonElement element : returns) {
                if (!element.isJsonObject()) continue;
                JsonObject returned = element.getAsJsonObject();
                addBusinessRecordRow(records, first(returned, "nickname", "account"),
                    "退回给发送人 · 余额 " + normalizedAmount(Jsons.string(returned, "amount"))
                        + readableTimeSuffix(Jsons.string(returned, "created_at")));
            }
            if (claims.isEmpty() && returns.isEmpty()) addBusinessEmptyRow(records, "还没有领取或退回记录");
        } else if ("transfer".equals(type)) {
            icon.setImageResource(R.drawable.ic_transfer);
            amountView.setText("余额 " + normalizedAmount(Jsons.string(item, "amount")));
            summaryView.setText(first(item, "sender_name", "sender_account") + " 转给 "
                + first(item, "receiver_name", "receiver_account"));
            progress.setVisibility(View.GONE);
            progressText.setVisibility(View.GONE);
            recordsLabel.setText("转账信息");
            addBusinessRecordRow(records, "留言", emptyFallback(Jsons.string(item, "message"), "未填写留言"));
            addBusinessRecordRow(records, "有效期", emptyFallback(Jsons.string(item, "expired_at"), "未设置"));
        } else {
            icon.setImageResource(R.drawable.ic_gift);
            String giftName = emptyFallback(Jsons.string(item, "gift_name"), "礼物");
            long quantity = Math.max(1L, Jsons.longValue(item, "quantity"));
            amountView.setText(giftName + " × " + quantity);
            summaryView.setText(first(item, "sender_name", "sender_account") + " 赠送给 "
                + first(item, "receiver_name", "receiver_account"));
            progress.setVisibility(View.GONE);
            progressText.setVisibility(View.GONE);
            recordsLabel.setText("礼物信息");
            addBusinessRecordRow(records, "留言", emptyFallback(Jsons.string(item, "message"), "未填写留言"));
            addBusinessRecordRow(records, "价值", "余额 " + normalizedAmount(Jsons.string(item, "total_amount")));
            addBusinessRecordRow(records, "有效期", emptyFallback(Jsons.string(item, "expired_at"), "未设置"));
        }

        if (boolValue(item, "can_claim")) {
            primary.setText("领取红包");
            primary.setVisibility(View.VISIBLE);
            primary.setOnClickListener(view -> runBusinessAction(
                type, title, path, path + "/claim", "红包领取失败", dialog));
        } else if (boolValue(item, "can_accept")) {
            primary.setText("确认收下");
            primary.setVisibility(View.VISIBLE);
            primary.setOnClickListener(view -> runBusinessAction(
                type, title, path, path + "/accept", "确认失败", dialog));
        }
        boolean canReturnPacket = "red_packet".equals(type) && boolValue(item, "can_return");
        if (canReturnPacket) {
            refund.setText("退回给发送人");
            refund.setVisibility(View.VISIBLE);
            refund.setOnClickListener(view -> confirmRedPacketReturn(() -> runBusinessAction(
                type, title, path, path + "/refund", "退回失败", dialog)));
        } else if (!"red_packet".equals(type) && boolValue(item, "can_refund")) {
            refund.setText("退回");
            refund.setVisibility(View.VISIBLE);
            refund.setOnClickListener(view -> runBusinessAction(
                type, title, path, path + "/refund", "退回失败", dialog));
        }
        if (records.getChildCount() == 0) {
            recordsLabel.setVisibility(View.GONE);
            recordsScroll.setVisibility(View.GONE);
        }
        GlassBottomSheet.prepare(dialog, this, 0.88f, false);
        dialog.show();
    }

    private void runBusinessAction(
        String type,
        String title,
        String detailPath,
        String actionPath,
        String fallback,
        BottomSheetDialog dialog
    ) {
        postQuickAction(actionPath, new JsonObject(), fallback, result -> {
            if (dialog.isShowing()) dialog.dismiss();
            if (binding == null || isFinishing() || isDestroyed()) return;
            Snackbar.make(binding.getRoot(), "操作成功", Snackbar.LENGTH_SHORT).show();
            refreshMessagesNow();
            handler.postDelayed(() -> {
                if (binding != null && !isFinishing() && !isDestroyed()) {
                    loadBusinessDetail(type, detailPath, title);
                }
            }, 250L);
        });
    }

    private void addBusinessRecordRow(LinearLayout records, String primary, String secondary) {
        LinearLayout row = new LinearLayout(this);
        row.setOrientation(LinearLayout.HORIZONTAL);
        row.setGravity(Gravity.CENTER_VERTICAL);
        row.setPadding(dp(4), dp(10), dp(4), dp(10));
        TextView name = new TextView(this);
        name.setText(emptyFallback(primary, "用户"));
        name.setTextSize(14);
        name.setTextColor(getColor(R.color.on_surface));
        TextView detail = new TextView(this);
        detail.setText(secondary);
        detail.setTextSize(13);
        detail.setGravity(Gravity.END);
        detail.setTextColor(getColor(R.color.on_surface_variant));
        row.addView(name, new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f));
        row.addView(detail, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.WRAP_CONTENT, ViewGroup.LayoutParams.WRAP_CONTENT));
        records.addView(row, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));
    }

    private void addBusinessEmptyRow(LinearLayout records, String text) {
        TextView empty = new TextView(this);
        empty.setText(text);
        empty.setGravity(Gravity.CENTER);
        empty.setTextSize(14);
        empty.setTextColor(getColor(R.color.on_surface_variant));
        empty.setPadding(dp(8), dp(26), dp(8), dp(26));
        records.addView(empty, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));
    }

    private void applyBusinessStateToMessages(String type, JsonObject item) {
        long targetId = businessRecordId(type, item);
        if (targetId <= 0L) return;
        boolean changed = false;
        for (JsonObject message : messages.values()) {
            JsonArray attachments = Jsons.array(message, "attachments");
            for (JsonElement element : attachments) {
                if (!element.isJsonObject()) continue;
                JsonObject attachment = element.getAsJsonObject();
                if (!type.equals(Jsons.string(attachment, "media_type"))) continue;
                JsonObject metadata;
                if (attachment.has("metadata") && attachment.get("metadata").isJsonObject()) {
                    metadata = attachment.getAsJsonObject("metadata");
                } else {
                    metadata = new JsonObject();
                    attachment.add("metadata", metadata);
                }
                if (businessRecordId(type, metadata) != targetId) continue;
                for (String key : new String[]{
                    "status", "total_amount", "remain_amount", "total_count", "remain_count",
                    "amount", "quantity", "expired_at", "accepted_at", "refunded_at",
                    "claimed", "returned", "returned_at", "settlement_status", "commerce_state"
                }) {
                    if (item.has(key)) metadata.add(key, item.get(key).deepCopy());
                }
                metadata.addProperty("commerce_state", currentBusinessState(type, item));
                changed = true;
            }
        }
        if (changed) adapter.submit(orderedMessageSnapshot());
    }

    private long businessRecordId(String type, JsonObject value) {
        if ("red_packet".equals(type)) {
            long id = Jsons.longValue(value, "packet_id");
            return id > 0L ? id : Jsons.longValue(value, "id");
        }
        String key = "gift".equals(type) ? "gift_record_id" : "transfer_id";
        long id = Jsons.longValue(value, key);
        if (id <= 0L) id = firstItemId(value, "items");
        return id > 0L ? id : Jsons.longValue(value, "id");
    }

    private String currentBusinessState(String type, JsonObject item) {
        String explicit = Jsons.string(item, "commerce_state").trim().toLowerCase(java.util.Locale.ROOT);
        if (!explicit.isEmpty()) return explicit;
        if ("red_packet".equals(type)) {
            if (boolValue(item, "claimed")) return "claimed";
            if (boolValue(item, "returned") || "returned".equalsIgnoreCase(Jsons.string(item, "settlement_status"))) {
                return "returned";
            }
            long status = Jsons.longValue(item, "status");
            if (status == 2L) return "refunded";
            if (status == 0L || (item.has("remain_count") && Jsons.longValue(item, "remain_count") <= 0L)) return "completed";
            return "pending";
        }
        String status = Jsons.string(item, "status").trim().toLowerCase(java.util.Locale.ROOT);
        return status.isEmpty() ? "pending" : status;
    }

    private String currentBusinessStateLabel(String type, String state) {
        if ("completed".equals(state)) return "已领完";
        if ("claimed".equals(state)) return "已领取";
        if ("accepted".equals(state)) return "gift".equals(type) ? "已收下" : "已收款";
        if ("refunded".equals(state)) return "已退回";
        if ("returned".equals(state)) return "已退回给发送人";
        if ("expired".equals(state)) return "已过期";
        if ("cancelled".equals(state)) return "已取消";
        return "red_packet".equals(type) ? "待领取" : "等待确认";
    }

    private boolean businessStateIsFinal(String state) {
        return "completed".equals(state) || "accepted".equals(state) || "refunded".equals(state)
            || "claimed".equals(state) || "returned".equals(state)
            || "expired".equals(state) || "cancelled".equals(state);
    }

    private static String normalizedAmount(String value) {
        if (value == null || value.trim().isEmpty()) return "0.00";
        try {
            return new BigDecimal(value.trim()).setScale(2, RoundingMode.HALF_UP).toPlainString();
        } catch (NumberFormatException ignored) {
            return value.trim();
        }
    }

    private static String emptyFallback(String value, String fallback) {
        return value == null || value.trim().isEmpty() ? fallback : value.trim();
    }

    private static String readableTimeSuffix(String value) {
        return value == null || value.trim().isEmpty() ? "" : " · " + value.trim();
    }

    private void sendGeneratedMessage(String text, String tag) {
        hideMediaPanel();
        pendingTags.clear();
        pendingTags.add(tag);
        binding.messageInput.setText(text);
        binding.messageInput.setSelection(binding.messageInput.length());
        send();
    }

    private void installWindowInsets() {
        WindowCompat.setDecorFitsSystemWindows(getWindow(), false);
        ViewCompat.setOnApplyWindowInsetsListener(binding.getRoot(), (view, windowInsets) -> {
            Insets bars = windowInsets.getInsets(WindowInsetsCompat.Type.systemBars());
            Insets ime = windowInsets.getInsets(WindowInsetsCompat.Type.ime());
            view.setPadding(bars.left, bars.top, bars.right, Math.max(bars.bottom, ime.bottom));
            return windowInsets;
        });
        ViewCompat.requestApplyInsets(binding.getRoot());
    }

    @Override protected void onStart() {
        super.onStart();
        running = true;
        registerMessageRefreshReceiver();
        registerRecentPhotoObserver();
        loadRecentSuggestion();
        loadMessages();
    }

    @Override protected void onResume() {
        super.onResume();
        applyChatBackground();
    }

    @Override protected void onAppearancePreferenceChanged(String key) {
        super.onAppearancePreferenceChanged(key);
        if (ChatBackgroundStore.isBackgroundPreference(key)) applyChatBackground();
    }

    @Override protected void onStop() {
        running = false;
        unregisterMessageRefreshReceiver();
        unregisterRecentPhotoObserver();
        handler.removeCallbacks(recentPhotoReload);
        recentSuggestionRecheckPending = false;
        handler.removeCallbacks(poll);
        handler.removeCallbacks(saveDraftTask);
        saveDraftRemote();
        invalidateMessageRequest();
        super.onStop();
    }

    private void registerMessageRefreshReceiver() {
        if (messageRefreshReceiverRegistered) return;
        ContextCompat.registerReceiver(this, messageRefreshReceiver,
            new IntentFilter(MessageNotificationService.ACTION_MESSAGES_CHANGED),
            ContextCompat.RECEIVER_NOT_EXPORTED);
        messageRefreshReceiverRegistered = true;
    }

    private void unregisterMessageRefreshReceiver() {
        if (!messageRefreshReceiverRegistered) return;
        try {
            unregisterReceiver(messageRefreshReceiver);
        } catch (IllegalArgumentException ignored) { }
        messageRefreshReceiverRegistered = false;
    }

    private boolean matchesChangedConversation(String type, long targetId) {
        if ("group".equals(type)) {
            return MODE_ROOM.equals(mode()) && targetId == getIntent().getLongExtra(EXTRA_TARGET_ID, 0L);
        }
        if ("private".equals(type)) {
            if (!MODE_CONVERSATION.equals(mode())) return false;
            long currentTargetId = getIntent().getLongExtra(EXTRA_TARGET_ID, 0L);
            return currentTargetId <= 0L || targetId == currentTargetId;
        }
        return "service".equals(type) && MODE_SERVICE_USER.equals(mode());
    }

    private void registerRecentPhotoObserver() {
        if (recentPhotoObserverRegistered) return;
        try {
            getContentResolver().registerContentObserver(
                MediaStore.Images.Media.EXTERNAL_CONTENT_URI,
                true,
                recentPhotoObserver
            );
            recentPhotoObserverRegistered = true;
        } catch (RuntimeException ignored) { }
    }

    private void unregisterRecentPhotoObserver() {
        if (!recentPhotoObserverRegistered) return;
        try {
            getContentResolver().unregisterContentObserver(recentPhotoObserver);
        } catch (RuntimeException ignored) { }
        recentPhotoObserverRegistered = false;
    }

    private void loadPolicy() {
        policyRequest = AppAccess.from(this).repository().getPublic("/api/public/bootstrap", new LinkedHashMap<>(), result -> {
            if (!result.isSuccessful()) return;
            UploadPolicyStore.update(this, Jsons.object(result.dataObject(), "upload_limits"));
            JsonObject policy = Jsons.object(result.dataObject(), "chat_polling_policy");
            long configured = Jsons.longValue(policy, "effective_interval_ms");
            if (configured == 0) configured = Jsons.longValue(policy, "interval_ms");
            if (configured > 0) pollIntervalMs = Math.max(1000L, Math.min(60000L, configured));
        });
    }

    private void loadMessages() {
        if (!running) return;
        if (messageRequestInFlight) {
            messageRefreshQueued = true;
            return;
        }
        if (MODE_CONVERSATION.equals(mode()) && getIntent().getLongExtra(EXTRA_TARGET_ID, 0) <= 0) {
            resolveExistingConversation();
            return;
        }
        handler.removeCallbacks(poll);
        Map<String, String> query = new LinkedHashMap<>();
        boolean contextSearch = (!searchQuery.isEmpty() || !"all".equals(searchContentFilter))
            && AppAccess.from(this).session().role() == Role.USER && !MODE_SERVICE_ADMIN.equals(mode());
        String requestPath = readPath();
        if (contextSearch) {
            requestPath = "/api/user/chat-search";
            query.put("scope_type", searchScope());
            query.put("target_id", String.valueOf(searchTargetId()));
            query.put("keyword", searchQuery);
            query.put("content_filter", searchContentFilter);
            query.put("context_size", "3");
            query.put("limit", "30");
        } else {
            query.put("limit", "100");
            if (!searchQuery.isEmpty()) query.put("keyword", searchQuery);
            else if (lastId > 0 && !MODE_SERVICE_ADMIN.equals(mode()) && incrementalPollCount < 30) {
                query.put("since_id", String.valueOf(lastId));
            }
        }
        final String path = requestPath;
        final boolean fullReconciliation = !contextSearch && searchQuery.isEmpty() && lastId > 0
            && !query.containsKey("since_id") && !MODE_SERVICE_ADMIN.equals(mode());
        final long requestGeneration = ++messageRequestGeneration;
        if (firstLoad && !contextSearch) loadCachedMessages(path, query, requestGeneration);
        messageRequestInFlight = true;
        messageRefreshQueued = false;
        if (firstLoad) binding.progress.setVisibility(View.VISIBLE);
        pollRequest = AppAccess.from(this).repository().get(path, query, result -> {
            if (requestGeneration != messageRequestGeneration) return;
            messageRequestInFlight = false;
            pollRequest = null;
            if (binding == null) return;
            binding.progress.setVisibility(View.INVISIBLE);
            if (result.isAuthenticationFailure()) { login(); return; }
            if (result.isSuccessful()) {
                incrementalPollCount = fullReconciliation ? 0 : Math.min(30, incrementalPollCount + 1);
                if (MODE_SERVICE_USER.equals(mode())) {
                    long resolvedSession = contextSearch ? Jsons.longValue(result.dataObject(), "target_id")
                        : Jsons.longValue(Jsons.object(result.dataObject(), "session"), "id");
                    if (resolvedSession > 0) serviceSessionId = resolvedSession;
                }
                boolean wasAtLatest = firstLoad || (!userHoldingHistory && isAtLatestMessage());
                ViewportAnchor readingAnchor = contextSearch || wasAtLatest
                    ? null : captureHistoryViewportAnchor();
                MessageMergeResult merged = merge(result.items());
                if (merged.changed || adapter.getItemCount() == 0) {
                    adapter.submit(orderedMessageSnapshot());
                    scheduleViewportAnchorRestore(readingAnchor, 0L, 120L);
                }
                binding.emptyText.setVisibility(messages.isEmpty() ? View.VISIBLE : View.GONE);
                if (contextSearch) {
                    int matches = Jsons.intValue(result.dataObject(), "match_count", 0);
                    binding.searchSummary.setText(matches == 0 ? "没有找到相关聊天内容"
                        : "找到 " + matches + " 条结果，已保留每条结果前后各 3 条上下文");
                    scrollToFirstSearchMatch();
                    loadSearchHistory();
                } else {
                    if (pendingFocusMessageId > 0L) {
                        focusPendingMention();
                    } else if (firstLoad || wasAtLatest) {
                        scrollToLatestMessage(false);
                        markGroupRead();
                    } else if (merged.added > 0) {
                        pendingNewMessageCount = ChatViewportPolicy.nextPendingCount(
                            pendingNewMessageCount, merged.added, false);
                        renderNewMessageIndicator();
                    }
                }
                firstLoad = false;
            } else if (firstLoad) {
                Snackbar.make(binding.getRoot(), result.message().isEmpty() ? "消息加载失败" : result.message(), Snackbar.LENGTH_LONG).show();
            }
            scheduleNextMessageSync();
        });
    }

    private void loadCachedMessages(String path, Map<String, String> query, long generation) {
        if (cachedMessageRequest != null) cachedMessageRequest.cancel();
        cachedMessageRequest = AppAccess.from(this).repository().getCached(path,
            new LinkedHashMap<>(query), result -> {
                cachedMessageRequest = null;
                if (!running || binding == null || !firstLoad || generation != messageRequestGeneration
                    || !result.isSuccessful()) return;
                MessageMergeResult merged = merge(result.items());
                if (merged.changed || adapter.getItemCount() == 0) {
                    adapter.submit(orderedMessageSnapshot());
                }
                binding.progress.setVisibility(View.INVISIBLE);
                binding.emptyText.setVisibility(messages.isEmpty() ? View.VISIBLE : View.GONE);
                if (!messages.isEmpty()) scrollToLatestMessage(false);
            });
    }

    private void scheduleNextMessageSync() {
        if (!running || binding == null || !searchQuery.isEmpty()) return;
        handler.removeCallbacks(poll);
        if (messageRefreshQueued) {
            messageRefreshQueued = false;
            handler.post(poll);
        } else {
            handler.postDelayed(poll, pollIntervalMs);
        }
    }

    private void resolveExistingConversation() {
        if (!running || binding == null || conversationResolveRequest != null) return;
        long peerId = resolvedConversationPeerId();
        if (peerId <= 0L) {
            showUnstartedConversation();
            return;
        }
        handler.removeCallbacks(poll);
        final long generation = ++conversationResolveGeneration;
        if (firstLoad) binding.progress.setVisibility(View.VISIBLE);
        Map<String, String> query = new LinkedHashMap<>();
        query.put("page", "1");
        query.put("limit", "200");
        loadCachedConversation(peerId, query, generation);
        conversationResolveRequest = AppAccess.from(this).repository().get(
            "/api/user/conversations", query, result -> {
                if (generation != conversationResolveGeneration) return;
                conversationResolveRequest = null;
                if (!running || binding == null) return;
                binding.progress.setVisibility(View.INVISIBLE);
                if (getIntent().getLongExtra(EXTRA_TARGET_ID, 0L) > 0L) return;
                if (result.isAuthenticationFailure()) { login(); return; }
                if (result.isSuccessful()) {
                    for (JsonObject item : result.objectItems()) {
                        if (Jsons.longValue(item, "peer_user_id") != peerId) continue;
                        long conversationId = Jsons.longValue(item, "id");
                        if (conversationId <= 0L) conversationId = Jsons.longValue(item, "target_id");
                        if (conversationId <= 0L) continue;
                        getIntent().putExtra(EXTRA_TARGET_ID, conversationId);
                        String title = first(item, "remark", "peer_name", "peer_account", "title", "nickname", "account");
                        if (!title.isEmpty() && !"未命名".equals(title)) {
                            normalTitle = title;
                            RuntimeLanguage.setDynamicToolbarTitle(binding.toolbar, title);
                        }
                        binding.emptyText.setVisibility(View.GONE);
                        loadMessages();
                        return;
                    }
                }
                if (cachedConversationResolveRequest == null) showUnstartedConversation();
            });
    }

    private void loadCachedConversation(long peerId, Map<String, String> query, long generation) {
        if (cachedConversationResolveRequest != null) cachedConversationResolveRequest.cancel();
        cachedConversationResolveRequest = AppAccess.from(this).repository().getCached(
            "/api/user/conversations", new LinkedHashMap<>(query), result -> {
                cachedConversationResolveRequest = null;
                if (!running || binding == null || generation != conversationResolveGeneration
                    || getIntent().getLongExtra(EXTRA_TARGET_ID, 0L) > 0L) return;
                if (result.isSuccessful() && applyCachedConversation(result.objectItems(), peerId)) return;
                if (conversationResolveRequest == null) showUnstartedConversation();
            });
    }

    private boolean applyCachedConversation(List<JsonObject> items, long peerId) {
        if (items == null || binding == null) return false;
        for (JsonObject item : items) {
            if (Jsons.longValue(item, "peer_user_id") != peerId) continue;
            long conversationId = Jsons.longValue(item, "id");
            if (conversationId <= 0L) conversationId = Jsons.longValue(item, "target_id");
            if (conversationId <= 0L) continue;
            getIntent().putExtra(EXTRA_TARGET_ID, conversationId);
            String title = first(item, "remark", "peer_name", "peer_account", "title", "nickname", "account");
            if (!title.isEmpty() && !"\u672a\u77e5".equals(title)) {
                normalTitle = title;
                RuntimeLanguage.setDynamicToolbarTitle(binding.toolbar, title);
            }
            binding.emptyText.setVisibility(View.GONE);
            loadMessages();
            return true;
        }
        return false;
    }

    private void showUnstartedConversation() {
        if (binding == null) return;
        binding.progress.setVisibility(View.INVISIBLE);
        binding.emptyText.setText("还没有聊天记录，发一条消息开始会话");
        binding.emptyText.setVisibility(View.VISIBLE);
        firstLoad = false;
        if (running) {
            handler.removeCallbacks(poll);
            handler.postDelayed(poll, pollIntervalMs);
        }
    }

    private void invalidateMessageRequest() {
        handler.removeCallbacks(poll);
        messageRequestGeneration++;
        conversationResolveGeneration++;
        messageRequestInFlight = false;
        messageRefreshQueued = false;
        if (pollRequest != null) pollRequest.cancel();
        pollRequest = null;
        if (cachedMessageRequest != null) cachedMessageRequest.cancel();
        cachedMessageRequest = null;
        if (cachedConversationResolveRequest != null) cachedConversationResolveRequest.cancel();
        cachedConversationResolveRequest = null;
        if (conversationResolveRequest != null) conversationResolveRequest.cancel();
        conversationResolveRequest = null;
    }

    private void scrollToFirstSearchMatch() {
        List<JsonObject> visible = adapter.messages();
        for (int index = 0; index < visible.size(); index++) {
            if (boolValue(visible.get(index), "is_search_match")) {
                binding.recycler.scrollToPosition(index);
                return;
            }
        }
    }

    private boolean isAtLatestMessage() {
        if (messageLayoutManager == null || adapter == null || adapter.getItemCount() == 0) return true;
        int lastVisible = messageLayoutManager.findLastVisibleItemPosition();
        if (!ChatViewportPolicy.isAtLatest(lastVisible, adapter.getItemCount())) return false;
        View lastView = messageLayoutManager.findViewByPosition(lastVisible);
        if (lastView == null || binding == null) return true;
        int viewportBottom = binding.recycler.getHeight() - binding.recycler.getPaddingBottom();
        return messageLayoutManager.getDecoratedBottom(lastView) <= viewportBottom + dp(4);
    }

    private ViewportAnchor captureHistoryViewportAnchor() {
        if (binding == null || messageLayoutManager == null || adapter == null
            || adapter.getItemCount() == 0 || (!userHoldingHistory && isAtLatestMessage())) return null;
        int first = messageLayoutManager.findFirstVisibleItemPosition();
        if (first == RecyclerView.NO_POSITION) return null;
        View firstView = messageLayoutManager.findViewByPosition(first);
        int offset = firstView == null ? 0
            : messageLayoutManager.getDecoratedTop(firstView) - binding.recycler.getPaddingTop();
        return new ViewportAnchor(adapter.getItemId(first), first, offset);
    }

    private void restoreViewportAnchor(ViewportAnchor anchor) {
        if (anchor == null || binding == null || messageLayoutManager == null || adapter == null
            || adapter.getItemCount() == 0) return;
        int position = adapter.positionOf(anchor.messageId);
        if (position < 0) position = Math.min(anchor.fallbackPosition, adapter.getItemCount() - 1);
        if (position >= 0) messageLayoutManager.scrollToPositionWithOffset(position, anchor.offset);
    }

    private void scheduleViewportAnchorRestore(ViewportAnchor anchor, long... delays) {
        if (anchor == null || binding == null) return;
        long generation = ++viewportRestoreGeneration;
        for (long delay : delays) {
            handler.postDelayed(() -> {
                if (binding == null || generation != viewportRestoreGeneration
                    || binding.recycler.getScrollState() == RecyclerView.SCROLL_STATE_DRAGGING) return;
                restoreViewportAnchor(anchor);
                renderNewMessageIndicator();
            }, Math.max(0L, delay));
        }
    }

    private void preserveHistoryViewportAcrossLayoutChange() {
        scheduleViewportAnchorRestore(captureHistoryViewportAnchor(), 0L, 90L, 220L);
    }

    private void updateLatestMessageState() {
        if (binding == null) return;
        if (userHoldingHistory || !isAtLatestMessage()) {
            renderNewMessageIndicator();
            return;
        }
        if (pendingNewMessageCount > 0) {
            pendingNewMessageCount = 0;
        }
        renderNewMessageIndicator();
        markGroupRead();
    }

    private void scrollToLatestMessage(boolean smooth) {
        if (binding == null || adapter == null || adapter.getItemCount() == 0) return;
        userHoldingHistory = false;
        viewportRestoreGeneration++;
        int target = adapter.getItemCount() - 1;
        if (smooth) binding.recycler.smoothScrollToPosition(target);
        else binding.recycler.scrollToPosition(target);
        pendingNewMessageCount = 0;
        renderNewMessageIndicator();
        binding.recycler.post(this::markGroupRead);
    }

    private void renderNewMessageIndicator() {
        if (binding == null) return;
        if (!userHoldingHistory && isAtLatestMessage()) {
            binding.newMessageIndicator.setVisibility(View.GONE);
            return;
        }
        binding.newMessageIndicator.setText(pendingNewMessageCount > 0
            ? "新消息(" + pendingNewMessageCount + ") ↓"
            : "回到底部 ↓");
        binding.newMessageIndicator.setVisibility(View.VISIBLE);
    }

    private String searchScope() {
        if (MODE_ROOM.equals(mode())) return "group";
        if (MODE_CONVERSATION.equals(mode())) return "private";
        return "service";
    }

    private String redPacketDeliveryScope() {
        if (MODE_CONVERSATION.equals(mode())) return "private";
        if (MODE_ROOM.equals(mode())) return "group";
        return "service";
    }

    private long searchTargetId() {
        if (MODE_SERVICE_USER.equals(mode())) return Math.max(0, serviceSessionId);
        return Math.max(0, getIntent().getLongExtra(EXTRA_TARGET_ID, 0));
    }

    private void markGroupRead() {
        if (!MODE_ROOM.equals(mode()) || AppAccess.from(this).session().role() != Role.USER
            || lastId <= 0 || lastId <= markedReadId || readRequest != null) return;
        JsonObject body = new JsonObject();
        body.addProperty("message_id", lastId);
        long requestedId = lastId;
        readRequest = AppAccess.from(this).repository().post(
            "/api/user/chat-rooms/" + getIntent().getLongExtra(EXTRA_TARGET_ID, 0) + "/read", body, result -> {
                readRequest = null;
                if (result.isSuccessful()) markedReadId = Math.max(markedReadId, requestedId);
            });
    }

    private MessageMergeResult merge(JsonArray items) {
        boolean changed = false;
        boolean requiresNormalization = false;
        if (MODE_SERVICE_ADMIN.equals(mode()) && lastId > 0 && !messages.isEmpty()) {
            messages.clear();
            changed = true;
        }
        int added = 0;
        for (JsonElement element : items) {
            if (!element.isJsonObject()) continue;
            JsonObject item = element.getAsJsonObject();
            observeConversationPeer(item);
            if ("recall".equals(Jsons.string(item, "content_type"))) {
                long recalledId = Jsons.longValue(item, "recalled_message_id");
                if (recalledId > 0 && messages.remove(recalledId) != null) changed = true;
            }
            long rawId = Jsons.longValue(item, "id");
            long id = rawId;
            if (id <= 0) id = item.toString().hashCode();
            JsonObject previous = messages.get(id);
            if (previous == null) {
                added++;
                changed = true;
                if (rawId > 0 && lastId > 0 && rawId < lastId) requiresNormalization = true;
                messages.put(id, item);
            } else if (!previous.equals(item)) {
                changed = true;
                messages.put(id, item);
            }
            lastId = Math.max(lastId, rawId);
        }
        if (requiresNormalization) normalizeMessageOrder();
        return new MessageMergeResult(added, changed);
    }

    private void observeConversationPeer(JsonObject item) {
        if (!MODE_CONVERSATION.equals(mode()) || item == null || resolvedPeerId > 0L) return;
        long selfId = AppAccess.from(this).session().actorId();
        long senderId = Jsons.longValue(item, "sender_id");
        long receiverId = Jsons.longValue(item, "receiver_user_id");
        long candidate = Jsons.longValue(item, "peer_user_id");
        if (candidate <= 0L) {
            if (senderId > 0L && senderId != selfId) candidate = senderId;
            else if (receiverId > 0L && receiverId != selfId) candidate = receiverId;
        }
        if (candidate <= 0L || candidate == selfId) return;
        resolvedPeerId = candidate;
        getIntent().putExtra(EXTRA_PEER_ID, candidate);
    }

    private long resolvedConversationPeerId() {
        if (!MODE_CONVERSATION.equals(mode())) return 0L;
        if (resolvedPeerId > 0L) return resolvedPeerId;
        long explicit = getIntent().getLongExtra(EXTRA_PEER_ID, 0L);
        if (explicit > 0L) {
            resolvedPeerId = explicit;
            return explicit;
        }
        for (JsonObject message : messages.values()) observeConversationPeer(message);
        return resolvedPeerId;
    }

    private void normalizeMessageOrder() {
        List<JsonObject> ordered = orderedMessageSnapshot();
        messages.clear();
        for (JsonObject item : ordered) {
            long id = Jsons.longValue(item, "id");
            if (id <= 0) id = item.toString().hashCode();
            messages.put(id, item);
        }
    }

    private List<JsonObject> orderedMessageSnapshot() {
        List<JsonObject> ordered = new ArrayList<>(messages.values());
        boolean orderedAlready = true;
        for (int index = 1; index < ordered.size(); index++) {
            if (compareMessages(ordered.get(index - 1), ordered.get(index)) > 0) {
                orderedAlready = false;
                break;
            }
        }
        if (!orderedAlready) ordered.sort(ChatActivity::compareMessages);
        return ordered;
    }

    private static int compareMessages(JsonObject left, JsonObject right) {
        long leftId = Jsons.longValue(left, "id");
        long rightId = Jsons.longValue(right, "id");
        if (leftId > 0 && rightId > 0) return Long.compare(leftId, rightId);
        int created = Jsons.string(left, "created_at").compareTo(Jsons.string(right, "created_at"));
        if (created != 0) return created;
        return Long.compare(left.toString().hashCode(), right.toString().hashCode());
    }

    private void send() {
        dismissRecentSuggestion();
        String content = binding.messageInput.getText() == null ? "" : binding.messageInput.getText().toString().trim();
        if ((content.isEmpty() && pendingAttachments.isEmpty()) || sendRequest != null
            || uploadRequest != null || activeUploadBatch != null
            || attachmentPreparationCount > 0) return;
        keepKeyboardAfterSend = binding.messageInput.hasFocus()
            && binding.messageInputLayout.getVisibility() == View.VISIBLE
            && binding.mediaPanel.getVisibility() != View.VISIBLE;
        submittedComposerEditVersion = composerEditVersion;
        setComposerEnabled(false);
        startBatchUpload(content);
    }

    private void startBatchUpload(String content) {
        BatchUploadState state = new BatchUploadState(
            content, new ArrayList<>(pendingAttachments), useOriginalMedia);
        activeUploadBatch = state;
        batchUploadRequests.clear();
        binding.progress.setVisibility(View.VISIBLE);
        for (int index = 0; index < state.attachments.size(); index++) {
            PendingAttachment attachment = state.attachments.get(index);
            if (!attachment.needsUpload()) {
                state.uploadedValues[index] = structuredAttachmentValue(attachment);
                state.completed++;
            }
        }
        pumpBatchUploads(state);
    }

    private void pumpBatchUploads(BatchUploadState state) {
        if (activeUploadBatch != state || state.failed || binding == null) return;
        while (state.inFlight < MAX_CONCURRENT_UPLOADS && state.nextIndex < state.attachments.size()) {
            int index = state.nextIndex++;
            if (state.uploadedValues[index] != null) continue;
            startBatchAttachmentUpload(state, index);
        }
        updateBatchUploadProgress(state);
        if (state.completed == state.attachments.size() && state.inFlight == 0) {
            activeUploadBatch = null;
            batchUploadRequests.clear();
            JsonArray values = new JsonArray();
            for (JsonObject value : state.uploadedValues) values.add(value);
            postMessage(state.content, values);
        }
    }

    private void startBatchAttachmentUpload(BatchUploadState state, int index) {
        if (activeUploadBatch != state || state.failed || binding == null) return;
        PendingAttachment attachment = state.attachments.get(index);
        state.attempts[index]++;
        state.inFlight++;
        ContentUriRequestBody body = new ContentUriRequestBody(
            getContentResolver(), attachment.uri, attachment.mimeType, attachment.sizeBytes);
        Map<String, String> fields = new LinkedHashMap<>();
        fields.put("scene", "message");
        fields.put("original_upload", state.originalUpload ? "1" : "0");
        RequestHandle request = AppAccess.from(this).repository().upload(
            uploadPath(), attachment.name, attachment.mimeType, body, fields, result -> {
                state.inFlight = Math.max(0, state.inFlight - 1);
                if (activeUploadBatch != state || state.failed || binding == null) return;
                if (result.isAuthenticationFailure()) {
                    failBatchUpload(state, index, "登录状态已失效，请重新登录");
                    login();
                    return;
                }
                if (!result.isSuccessful()) {
                    if (state.attempts[index] < MAX_UPLOAD_ATTEMPTS && isRetryableUploadFailure(result.httpCode())) {
                        updateBatchUploadProgress(state);
                        handler.postDelayed(() -> startBatchAttachmentUpload(state, index), 350L);
                        return;
                    }
                    failBatchUpload(state, index, uploadFailureMessage(result.httpCode(), result.message()));
                    return;
                }
                JsonObject uploaded = result.dataObject();
                long uploadId = Jsons.longValue(uploaded, "upload_id");
                if (uploadId <= 0) {
                    failBatchUpload(state, index, "服务器返回的上传结果不完整");
                    return;
                }
                state.uploadedValues[index] = uploadedAttachmentValue(attachment, uploadId);
                state.completed++;
                pumpBatchUploads(state);
            });
        batchUploadRequests.add(request);
    }

    private JsonObject structuredAttachmentValue(PendingAttachment attachment) {
        JsonObject value = new JsonObject();
        value.addProperty("media_type", attachment.mediaType);
        if (attachment.stickerId > 0) value.addProperty("sticker_id", attachment.stickerId);
        if (!attachment.previewUrl.isEmpty()) value.addProperty("url", attachment.previewUrl);
        else if (!"sticker".equals(attachment.mediaType)) value.addProperty("url", "/api/user/favorites");
        if (attachment.name != null && !attachment.name.isEmpty()) value.addProperty("file_name", attachment.name);
        if (!attachment.metadata.entrySet().isEmpty()) value.add("metadata", attachment.metadata.deepCopy());
        return value;
    }

    private JsonObject uploadedAttachmentValue(PendingAttachment attachment, long uploadId) {
        JsonObject value = new JsonObject();
        value.addProperty("media_type", attachment.mediaType);
        value.addProperty("upload_id", uploadId);
        value.addProperty("file_name", attachment.name);
        value.addProperty("mime_type", attachment.mimeType);
        if (attachment.sizeBytes > 0) value.addProperty("size_bytes", attachment.sizeBytes);
        if (attachment.width > 0) value.addProperty("width", attachment.width);
        if (attachment.height > 0) value.addProperty("height", attachment.height);
        if (attachment.durationMs > 0) value.addProperty("duration_ms", attachment.durationMs);
        if (!attachment.metadata.entrySet().isEmpty()) value.add("metadata", attachment.metadata.deepCopy());
        return value;
    }

    private void updateBatchUploadProgress(BatchUploadState state) {
        if (binding == null || activeUploadBatch != state) return;
        binding.progress.setVisibility(View.VISIBLE);
        binding.progress.setContentDescription("正在上传附件 " + state.completed + "/" + state.attachments.size());
    }

    private boolean isRetryableUploadFailure(int httpCode) {
        return httpCode == 0 || httpCode == 408 || httpCode == 425 || httpCode == 429 || httpCode >= 500;
    }

    private String uploadFailureMessage(int httpCode, String message) {
        if (httpCode == 413) return "文件超过服务器上传限制，请压缩后重试或联系管理员提高限制";
        if (httpCode == 408 || httpCode == 0) return "网络连接超时，请检查网络后重试";
        return message == null || message.trim().isEmpty() ? "附件上传失败" : message.trim();
    }

    private void failBatchUpload(BatchUploadState state, int index, String reason) {
        if (activeUploadBatch != state || state.failed) return;
        state.failed = true;
        activeUploadBatch = null;
        for (RequestHandle request : batchUploadRequests) request.cancel();
        batchUploadRequests.clear();
        if (binding == null) return;
        binding.progress.setVisibility(View.INVISIBLE);
        setComposerEnabled(true);
        String prefix = state.attachments.size() > 1
            ? "第 " + (index + 1) + "/" + state.attachments.size() + " 项上传失败："
            : "附件上传失败：";
        Snackbar.make(binding.getRoot(), prefix + reason + "。已保留全部待发送内容。", Snackbar.LENGTH_LONG).show();
    }

    private void postMessage(String content, JsonArray attachments) {
        String submittedDraftPath = draftPath();
        JsonObject body = new JsonObject();
        JsonObject quote = pendingQuote;
        if (quote != null) {
            long replyId = Jsons.longValue(quote, "id");
            if (replyId > 0) body.addProperty("reply_to_message_id", replyId);
        }
        body.addProperty("content", content);
        if (!attachments.isEmpty()) body.add("attachments", attachments);
        if (!pendingTags.isEmpty()) {
            JsonArray tags = new JsonArray();
            for (String tag : pendingTags) tags.add(tag);
            body.add("tags", tags);
        }
        if (!pendingMentionIds.isEmpty()) {
            JsonArray mentions = new JsonArray();
            for (Long userId : pendingMentionIds) mentions.add(userId);
            body.add("mentions", mentions);
        }
        if (MODE_CONVERSATION.equals(mode())) body.addProperty("to_user_id", resolvedConversationPeerId());
        sendRequest = AppAccess.from(this).repository().post(writePath(), body, result -> {
            sendRequest = null;
            if (binding == null) return;
            binding.progress.setVisibility(View.INVISIBLE);
            setComposerEnabled(true);
            if (result.isAuthenticationFailure()) { login(); return; }
            if (!result.isSuccessful()) {
                Snackbar.make(binding.getRoot(), result.message().isEmpty() ? "发送失败" : result.message(), Snackbar.LENGTH_LONG).show();
                return;
            }
            adoptCreatedConversation(result.dataObject());
            String activeDraftKey = draftKey();
            String currentInput = binding.messageInput.getText() == null
                ? ""
                : binding.messageInput.getText().toString();
            boolean composerUnchanged = composerEditVersion == submittedComposerEditVersion
                && currentInput.trim().equals(content);
            if (composerUnchanged) {
                binding.messageInput.setText("");
                pendingMentionIds.clear();
                getSharedPreferences("composer_drafts", 0).edit()
                    .remove(activeDraftKey).remove("draft_time:" + activeDraftKey)
                    .remove("conversation:0:" + resolvedConversationPeerId())
                    .remove("draft_time:conversation:0:" + resolvedConversationPeerId())
                    .apply();
                if (AppAccess.from(this).session().role() == Role.USER) {
                    AppAccess.from(this).repository().delete(submittedDraftPath, new JsonObject(), ignored -> { });
                    if (!submittedDraftPath.equals(draftPath())) {
                        AppAccess.from(this).repository().delete(draftPath(), new JsonObject(), ignored -> { });
                    }
                }
            }
            clearPendingAttachmentSelection();
            pendingTags.clear();
            useOriginalMedia = false;
            clearQuote();
            renderPendingAttachments();
            restoreContinuousComposer();
            refreshMessagesNow();
        });
    }

    private void restoreContinuousComposer() {
        if (!keepKeyboardAfterSend || binding == null || isFinishing() || isDestroyed()) return;
        binding.messageInput.requestFocus();
        binding.messageInput.setSelection(binding.messageInput.length());
        binding.messageInput.post(() -> {
            if (binding == null || !binding.messageInput.hasFocus()) return;
            android.view.inputmethod.InputMethodManager keyboard =
                (android.view.inputmethod.InputMethodManager) getSystemService(INPUT_METHOD_SERVICE);
            keyboard.showSoftInput(binding.messageInput,
                android.view.inputmethod.InputMethodManager.SHOW_IMPLICIT);
        });
    }

    private void refreshMessagesNow() {
        handler.removeCallbacks(poll);
        if (messageRequestInFlight) {
            messageRefreshQueued = true;
            return;
        }
        loadMessages();
    }

    private String uploadPath() {
        if (AppAccess.from(this).session().role() == Role.ADMIN) {
            return "/api/admin/apps/" + AppAccess.from(this).session().selectedAppId() + "/uploads";
        }
        return "/api/user/uploads";
    }

    private void setComposerEnabled(boolean enabled) {
        binding.sendButton.setEnabled(enabled);
        binding.attachButton.setEnabled(enabled);
        binding.voiceModeButton.setEnabled(enabled);
        binding.emojiButton.setEnabled(enabled);
        binding.holdToTalkButton.setEnabled(enabled);
        // Keep the editor attached to the IME so users can continue typing during the request.
        binding.messageInput.setEnabled(true);
    }

    private void showAttachmentMenu() {
        List<String> choices = new ArrayList<>();
        choices.add("从本地相册选择图片");
        choices.add("选择本地视频");
        choices.add("选择本地语音");
        choices.add("选择本地文件");
        if (AppAccess.from(this).session().role() == Role.USER) choices.add("选择我的表情包");
        if (!pendingAttachments.isEmpty()) choices.add("清空待发送附件");
        new YiyunyingDialogBuilder(this)
            .setTitle("添加到消息")
            .setItems(choices.toArray(new String[0]), (dialog, which) -> {
                String selected = choices.get(which);
                if (selected.startsWith("从本地相册")) openMediaPicker();
                else if (selected.startsWith("选择本地视频")) openDocumentPicker("video", "video/*");
                else if (selected.startsWith("选择本地语音")) openDocumentPicker("audio", "audio/*");
                else if (selected.startsWith("选择本地文件")) openCommonDocumentPicker();
                else if (selected.startsWith("选择我的表情")) loadStickers();
                else {
                    clearPendingAttachmentSelection();
                    renderPendingAttachments();
                }
            })
            .setNegativeButton("取消", null)
            .show();
    }

    private void openDocumentPicker(String mediaType, String mimeType) {
        pendingPickerType = mediaType;
        openCommonDocumentPicker();
    }

    private void openCommonDocumentPicker() {
        dismissRecentSuggestion();
        int available = Math.max(1, 200 - pendingAttachments.size());
        visualFilePicker.launch(FilePickerActivity.pickerIntent(this, Math.min(50, available)));
    }

    private void handleFilePickerResult(ActivityResult result) {
        if (result.getResultCode() != RESULT_OK || result.getData() == null) return;
        ArrayList<String> values = result.getData()
            .getStringArrayListExtra(FilePickerActivity.EXTRA_SELECTED_URIS);
        if (values == null || values.isEmpty()) return;
        List<Uri> uris = new ArrayList<>(values.size());
        for (String value : values) {
            if (value != null && !value.trim().isEmpty()) uris.add(Uri.parse(value));
        }
        selectedUris("file", uris);
    }

    private void selectedUris(String requestedType, List<Uri> uris) {
        if (binding == null || uris == null || uris.isEmpty()) return;
        int available = 200 - pendingAttachments.size() - preparingAttachmentUris.size();
        List<Uri> candidates = new ArrayList<>();
        Map<String, JsonObject> pickerMetadata = new LinkedHashMap<>();
        for (Uri uri : uris) {
            if (uri == null || available <= 0 || containsUri(uri)
                || preparingAttachmentUris.contains(uri.toString())) continue;
            String key = uri.toString();
            candidates.add(uri);
            preparingAttachmentUris.add(key);
            JsonObject metadata = pendingPickerMetadata.get(key);
            if (metadata != null) pickerMetadata.put(key, metadata.deepCopy());
            available--;
        }
        if (candidates.isEmpty()) {
            if (pendingAttachments.size() + preparingAttachmentUris.size() >= 200) {
                Snackbar.make(binding.getRoot(), "单条消息已达到 200 个媒体文件，请先发送这一批",
                    Snackbar.LENGTH_LONG).show();
            }
            return;
        }
        long generation = attachmentPreparationGeneration;
        attachmentPreparationCount++;
        updateComposerActions();
        mediaExecutor.execute(() -> {
            AttachmentPreparationResult result = prepareAttachments(
                requestedType, candidates, pickerMetadata);
            runOnUiThread(() -> applyPreparedAttachments(generation, candidates, result));
        });
    }

    private AttachmentPreparationResult prepareAttachments(
        String requestedType,
        List<Uri> uris,
        Map<String, JsonObject> pickerMetadata
    ) {
        AttachmentPreparationResult result = new AttachmentPreparationResult();
        for (Uri uri : uris) {
            if (Thread.currentThread().isInterrupted()) break;
            try {
                getContentResolver().takePersistableUriPermission(
                    uri, Intent.FLAG_GRANT_READ_URI_PERMISSION);
            } catch (RuntimeException ignored) { }
            JsonObject picker = pickerMetadata.get(uri.toString());
            String name = picker == null ? "" : Jsons.string(picker, "file_name");
            long size = picker != null && picker.has("size_bytes")
                ? Jsons.longValue(picker, "size_bytes") : -1L;
            if ("file".equalsIgnoreCase(uri.getScheme()) && uri.getPath() != null) {
                File local = new File(uri.getPath());
                if (name.isEmpty()) name = local.getName();
                if (size < 0L) size = local.length();
            }
            if (name.isEmpty() || size < 0L) {
                String[] projection = {OpenableColumns.DISPLAY_NAME, OpenableColumns.SIZE};
                try (Cursor cursor = getContentResolver().query(uri, projection, null, null, null)) {
                    if (cursor != null && cursor.moveToFirst()) {
                        int nameIndex = cursor.getColumnIndex(OpenableColumns.DISPLAY_NAME);
                        int sizeIndex = cursor.getColumnIndex(OpenableColumns.SIZE);
                        if (name.isEmpty() && nameIndex >= 0 && !cursor.isNull(nameIndex)) {
                            name = cursor.getString(nameIndex);
                        }
                        if (size < 0L && sizeIndex >= 0 && !cursor.isNull(sizeIndex)) {
                            size = cursor.getLong(sizeIndex);
                        }
                    }
                } catch (RuntimeException ignored) { }
            }
            if (name.isEmpty()) name = "本地文件";
            String mime = picker == null ? "" : Jsons.string(picker, "mime_type");
            if (mime.isEmpty()) {
                try { mime = getContentResolver().getType(uri); }
                catch (RuntimeException ignored) { }
            }
            if (mime == null || mime.trim().isEmpty()) mime = mimeFromName(name);
            String pickerType = picker == null ? "" : Jsons.string(picker, "media_type");
            String mediaType = "file".equals(requestedType)
                ? (pickerType.isEmpty() ? mediaTypeFromMime(mime, name) : pickerType)
                : requestedType;
            if (!UploadPolicyStore.accepts(this, mediaType, size)) {
                result.rejections.add(UploadPolicyStore.rejectionMessage(this, mediaType, size));
                continue;
            }
            long[] mediaValues = picker == null
                ? readMediaMetadata(uri, mediaType)
                : new long[] {
                    Jsons.longValue(picker, "width"),
                    Jsons.longValue(picker, "height"),
                    Jsons.longValue(picker, "duration_ms")
                };
            JsonObject mediaMetadata = new JsonObject();
            if ("image".equals(mediaType)) {
                boolean gif = MediaKindDetector.isGif(mime, name);
                mediaMetadata.addProperty("is_gif", picker == null ? gif
                    : metadataBoolean(picker, "is_gif"));
                boolean motionPhoto = picker != null
                    ? metadataBoolean(picker, "is_motion_photo")
                    : (!gif && lastCapturedMediaUri != null && lastCapturedMediaUri.equals(uri)
                        && MediaKindDetector.isMotionPhoto(this, uri, mime, name));
                mediaMetadata.addProperty("is_motion_photo", motionPhoto);
            }
            result.attachments.add(PendingAttachment.local(
                uri, mediaType, name, mime, size,
                (int) mediaValues[0], (int) mediaValues[1], mediaValues[2], mediaMetadata
            ));
        }
        return result;
    }

    private void applyPreparedAttachments(
        long generation,
        List<Uri> candidates,
        AttachmentPreparationResult result
    ) {
        if (generation != attachmentPreparationGeneration) return;
        for (Uri uri : candidates) preparingAttachmentUris.remove(uri.toString());
        attachmentPreparationCount = Math.max(0, attachmentPreparationCount - 1);
        if (binding == null) return;
        int available = 200 - pendingAttachments.size();
        int added = 0;
        for (PendingAttachment attachment : result.attachments) {
            if (available <= 0 || attachment.uri == null || containsUri(attachment.uri)) continue;
            pendingAttachments.add(attachment);
            available--;
            added++;
        }
        renderPendingAttachments();
        if (!result.rejections.isEmpty()) {
            String message = result.rejections.get(0);
            if (result.rejections.size() > 1) {
                message += "；另有 " + (result.rejections.size() - 1) + " 个文件不符合上传限制";
            }
            Snackbar.make(binding.getRoot(), message, Snackbar.LENGTH_LONG).show();
        } else if (added == 0 && pendingAttachments.size() >= 200) {
            Snackbar.make(binding.getRoot(), "单条消息已达到 200 个媒体文件，请先发送这一批",
                Snackbar.LENGTH_LONG).show();
        }
    }

    private void clearPendingAttachmentSelection() {
        attachmentPreparationGeneration++;
        attachmentPreparationCount = 0;
        preparingAttachmentUris.clear();
        pendingAttachments.clear();
    }

    private boolean containsUri(Uri uri) {
        for (PendingAttachment attachment : pendingAttachments) {
            if (uri.equals(attachment.uri)) return true;
        }
        return false;
    }

    private String mediaTypeFromMime(String mime, String name) {
        String value = mime.toLowerCase();
        if (value.startsWith("image/")) return "image";
        if (value.startsWith("audio/")) return "audio";
        if (value.startsWith("video/")) return "video";
        String lowerName = name == null ? "" : name.toLowerCase(java.util.Locale.ROOT);
        if (lowerName.matches(".*\\.(jpg|jpeg|png|gif|webp|bmp|heic|heif)$")) return "image";
        if (lowerName.matches(".*\\.(mp3|m4a|aac|wav|ogg|opus|flac)$")) return "audio";
        if (lowerName.matches(".*\\.(mp4|mov|mkv|avi|webm|3gp|m4v)$")) return "video";
        return "file";
    }

    private String mimeFromName(String name) {
        String value = name == null ? "" : name.toLowerCase(java.util.Locale.ROOT);
        if (value.endsWith(".jpg") || value.endsWith(".jpeg")) return "image/jpeg";
        if (value.endsWith(".png")) return "image/png";
        if (value.endsWith(".gif")) return "image/gif";
        if (value.endsWith(".webp")) return "image/webp";
        if (value.endsWith(".mp4") || value.endsWith(".m4v")) return "video/mp4";
        if (value.endsWith(".mov")) return "video/quicktime";
        if (value.endsWith(".mp3")) return "audio/mpeg";
        if (value.endsWith(".m4a")) return "audio/mp4";
        return "application/octet-stream";
    }

    @Override public void onRequestPermissionsResult(int requestCode, @NonNull String[] permissions, @NonNull int[] grantResults) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults);
        if (requestCode == REQUEST_CAMERA_PERMISSION) {
            if (grantResults.length > 0 && grantResults[0] == PackageManager.PERMISSION_GRANTED) {
                createCaptureTarget(pendingVideoCapture);
            } else if (binding != null) {
                Snackbar.make(binding.getRoot(), "未获得相机权限，无法拍照或录像", Snackbar.LENGTH_LONG).show();
            }
        } else if (requestCode == REQUEST_SPEECH_PERMISSION) {
            boolean granted = grantResults.length > 0 && grantResults[0] == PackageManager.PERMISSION_GRANTED;
            if (granted && pendingSpeechPermission) {
                boolean useServer = pendingServerSpeechPermission;
                pendingSpeechPermission = false;
                pendingServerSpeechPermission = false;
                if (useServer) startServerSpeechRecording("麦克风权限已开启，请开始说话，再点麦克风完成");
                else startSpeechRecognition();
            } else if (binding != null) {
                pendingSpeechPermission = false;
                pendingServerSpeechPermission = false;
                Snackbar.make(binding.getRoot(), "未获得麦克风权限，无法使用语音转文字", Snackbar.LENGTH_LONG).show();
            }
        } else if (requestCode == REQUEST_MEDIA_PERMISSION) {
            boolean granted = false;
            for (int result : grantResults) {
                if (result == PackageManager.PERMISSION_GRANTED) {
                    granted = true;
                    break;
                }
            }
            if (granted) loadInlineAlbum(true);
            else if (binding != null) {
                binding.inlineAlbumEmpty.setText("未获得相册权限，无法读取本机照片和视频");
                binding.inlineAlbumEmpty.setVisibility(View.VISIBLE);
            }
        }
    }

    private long[] readMediaMetadata(Uri uri, String mediaType) {
        long[] values = {0, 0, 0};
        if ("image".equals(mediaType)) {
            BitmapFactory.Options options = new BitmapFactory.Options();
            options.inJustDecodeBounds = true;
            try (InputStream stream = getContentResolver().openInputStream(uri)) {
                BitmapFactory.decodeStream(stream, null, options);
                values[0] = Math.max(0, options.outWidth);
                values[1] = Math.max(0, options.outHeight);
            } catch (RuntimeException | java.io.IOException ignored) { }
            return values;
        }
        if (!"video".equals(mediaType) && !"audio".equals(mediaType)) return values;
        MediaMetadataRetriever retriever = new MediaMetadataRetriever();
        try {
            retriever.setDataSource(this, uri);
            values[0] = parseLong(retriever.extractMetadata(MediaMetadataRetriever.METADATA_KEY_VIDEO_WIDTH));
            values[1] = parseLong(retriever.extractMetadata(MediaMetadataRetriever.METADATA_KEY_VIDEO_HEIGHT));
            values[2] = parseLong(retriever.extractMetadata(MediaMetadataRetriever.METADATA_KEY_DURATION));
        } catch (RuntimeException ignored) {
        } finally {
            try { retriever.release(); } catch (java.io.IOException ignored) { }
        }
        return values;
    }

    private long parseLong(String value) {
        try { return Math.max(0, Long.parseLong(value == null ? "0" : value)); }
        catch (NumberFormatException ignored) { return 0; }
    }

    private void renderPendingAttachments() {
        if (binding == null) return;
        binding.pendingContainer.removeAllViews();
        binding.pendingScroll.setVisibility(pendingAttachments.isEmpty() ? View.GONE : View.VISIBLE);
        for (int index = 0; index < pendingAttachments.size(); index++) {
            PendingAttachment attachment = pendingAttachments.get(index);
            Chip chip = new Chip(this);
            chip.setCheckable(false);
            chip.setCloseIconVisible(true);
            chip.setText(pendingLabel(attachment));
            int target = index;
            chip.setOnCloseIconClickListener(view -> {
                if (target >= 0 && target < pendingAttachments.size()) {
                    pendingAttachments.remove(target);
                    renderPendingAttachments();
                }
            });
            binding.pendingContainer.addView(chip);
        }
        if (!pendingAttachments.isEmpty()) {
            binding.pendingScroll.post(() -> binding.pendingScroll.fullScroll(View.FOCUS_RIGHT));
        }
        if (recentSuggestionUri != null) binding.recentSuggestionAdd.setText(containsUri(recentSuggestionUri) ? "已添加" : "添加");
        updateComposerActions();
    }

    private String pendingLabel(PendingAttachment attachment) {
        String type;
        switch (attachment.mediaType) {
            case "image":
                if (metadataBoolean(attachment.metadata, "is_gif")) type = "GIF";
                else if (metadataBoolean(attachment.metadata, "is_motion_photo")) type = "动态图";
                else type = "图片";
                break;
            case "sticker": type = "表情"; break;
            case "audio": type = attachment.durationMs > 0 ? "语音 " + Math.max(1, attachment.durationMs / 1000) + "秒" : "语音"; break;
            case "video": type = "视频"; break;
            default: type = "文件"; break;
        }
        return type + "  " + (attachment.name == null || attachment.name.isEmpty() ? "未命名" : attachment.name);
    }

    private boolean metadataBoolean(JsonObject metadata, String key) {
        if (metadata == null || key == null || !metadata.has(key)) return false;
        try {
            return metadata.get(key).getAsBoolean();
        } catch (RuntimeException ignored) {
            return false;
        }
    }

    private void loadStickers() {
        if (stickerRequest != null) return;
        binding.progress.setVisibility(View.VISIBLE);
        stickerRequest = AppAccess.from(this).repository().get("/api/user/sticker-packs", new LinkedHashMap<>(), result -> {
            stickerRequest = null;
            if (binding == null) return;
            binding.progress.setVisibility(View.INVISIBLE);
            if (result.isAuthenticationFailure()) { login(); return; }
            if (!result.isSuccessful()) {
                Snackbar.make(binding.getRoot(), result.message().isEmpty() ? "表情包加载失败" : result.message(), Snackbar.LENGTH_LONG).show();
                return;
            }
            showStickerPicker(result.items());
        });
    }

    private void uploadSticker(Uri uri) {
        if (uri == null || stickerRequest != null) return;
        String name = "我的表情"; long size = -1;
        try (Cursor cursor = getContentResolver().query(uri, null, null, null, null)) {
            if (cursor != null && cursor.moveToFirst()) {
                int nameIndex = cursor.getColumnIndex(OpenableColumns.DISPLAY_NAME);
                int sizeIndex = cursor.getColumnIndex(OpenableColumns.SIZE);
                if (nameIndex >= 0 && !cursor.isNull(nameIndex)) name = cursor.getString(nameIndex);
                if (sizeIndex >= 0 && !cursor.isNull(sizeIndex)) size = cursor.getLong(sizeIndex);
            }
        }
        String mime = getContentResolver().getType(uri); if (mime == null) mime = "image/png";
        if (!UploadPolicyStore.accepts(this, "image", size)) {
            Snackbar.make(binding.getRoot(), UploadPolicyStore.rejectionMessage(this, "image", size), Snackbar.LENGTH_LONG).show();
            return;
        }
        ContentUriRequestBody body = new ContentUriRequestBody(getContentResolver(), uri, mime, size);
        Map<String, String> fields = new LinkedHashMap<>(); fields.put("scene", "表情包");
        binding.progress.setVisibility(View.VISIBLE);
        String finalName = name;
        stickerRequest = AppAccess.from(this).repository().upload("/api/user/uploads", name, mime, body, fields, result -> {
            stickerRequest = null;
            if (binding == null) return;
            if (!result.isSuccessful()) { binding.progress.setVisibility(View.INVISIBLE); Snackbar.make(binding.getRoot(), result.message(), Snackbar.LENGTH_LONG).show(); return; }
            ensureStickerPack(Jsons.longValue(result.dataObject(), "upload_id"), finalName);
        });
    }

    private void ensureStickerPack(long uploadId, String name) {
        stickerRequest = AppAccess.from(this).repository().get("/api/user/sticker-packs", new LinkedHashMap<>(), result -> {
            stickerRequest = null;
            if (binding == null) return;
            long packId = 0;
            for (JsonObject pack : result.objectItems()) {
                if ("我的表情".equals(Jsons.string(pack, "name"))) { packId = Jsons.longValue(pack, "id"); break; }
            }
            if (packId > 0) { addSticker(packId, uploadId, name); return; }
            JsonObject body = new JsonObject(); body.addProperty("name", "我的表情");
            stickerRequest = AppAccess.from(this).repository().post("/api/user/sticker-packs", body, created -> {
                stickerRequest = null;
                if (!created.isSuccessful()) { binding.progress.setVisibility(View.INVISIBLE); Snackbar.make(binding.getRoot(), created.message(), Snackbar.LENGTH_LONG).show(); return; }
                addSticker(Jsons.longValue(created.dataObject(), "pack_id"), uploadId, name);
            });
        });
    }

    private void addSticker(long packId, long uploadId, String name) {
        JsonObject body = new JsonObject(); body.addProperty("upload_id", uploadId); body.addProperty("name", name);
        stickerRequest = AppAccess.from(this).repository().post("/api/user/sticker-packs/" + packId + "/stickers", body, result -> {
            stickerRequest = null;
            if (binding == null) return;
            binding.progress.setVisibility(View.INVISIBLE);
            Snackbar.make(binding.getRoot(), result.isSuccessful() ? "表情已上传，可立即选择发送" : result.message(), Snackbar.LENGTH_LONG).show();
            if (result.isSuccessful()) loadStickers();
        });
    }

    private void showStickerPicker(JsonArray packs) {
        GridLayout grid = binding.stickerGrid;
        grid.removeAllViews();
        grid.setColumnCount(4);
        grid.setPadding(dp(12), dp(12), dp(12), dp(12));
        List<JsonObject> stickers = new ArrayList<>();
        for (JsonElement packElement : packs) {
            if (!packElement.isJsonObject()) continue;
            JsonArray packStickers = Jsons.array(packElement.getAsJsonObject(), "stickers");
            for (JsonElement sticker : packStickers) if (sticker.isJsonObject()) stickers.add(sticker.getAsJsonObject());
        }
        for (JsonObject sticker : stickers) {
            MaterialCardView card = new MaterialCardView(this);
            GridLayout.LayoutParams cardParams = new GridLayout.LayoutParams();
            cardParams.width = dp(76);
            cardParams.height = dp(92);
            cardParams.setMargins(dp(4), dp(4), dp(4), dp(4));
            card.setLayoutParams(cardParams);
            card.setRadius(dp(6));
            card.setCardElevation(0);
            FrameLayout content = new FrameLayout(this);
            LinearLayout tile = new LinearLayout(this);
            tile.setGravity(android.view.Gravity.CENTER);
            tile.setOrientation(LinearLayout.VERTICAL);
            FrameLayout.LayoutParams tileParams = new FrameLayout.LayoutParams(
                FrameLayout.LayoutParams.MATCH_PARENT, FrameLayout.LayoutParams.MATCH_PARENT);
            tile.setLayoutParams(tileParams);
            ImageView image = new ImageView(this);
            image.setScaleType(ImageView.ScaleType.FIT_CENTER);
            tile.addView(image, new LinearLayout.LayoutParams(dp(58), dp(58)));
            TextView label = new TextView(this);
            String name = Jsons.string(sticker, "name");
            label.setText(name.isEmpty() ? "表情" : name);
            label.setGravity(android.view.Gravity.CENTER);
            label.setSingleLine(true);
            tile.addView(label, new LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, dp(26)));
            content.addView(tile);
            ImageView delete = new ImageView(this);
            delete.setImageResource(R.drawable.ic_close);
            delete.setColorFilter(Color.rgb(210, 64, 64));
            delete.setContentDescription("删除表情");
            delete.setPadding(dp(4), dp(4), dp(4), dp(4));
            FrameLayout.LayoutParams deleteParams = new FrameLayout.LayoutParams(dp(26), dp(26),
                Gravity.TOP | Gravity.END);
            deleteParams.setMargins(0, dp(2), dp(2), 0);
            content.addView(delete, deleteParams);
            card.addView(content);
            String preview = Jsons.string(sticker, "image_url");
            ImageLoader.get().load(ImageLoader.get().absoluteUrl(this, preview), image, xyz.jjmxg.yiyunying.R.drawable.ic_file);
            card.setOnClickListener(view -> sendStickerNow(sticker));
            card.setOnLongClickListener(view -> {
                previewSticker(sticker);
                return true;
            });
            delete.setOnClickListener(view -> confirmDeleteSticker(sticker));
            grid.addView(card);
        }
        if (stickers.isEmpty()) {
            TextView empty = new TextView(this);
            empty.setText("还没有个人表情包，可在“我的”中上传图片后创建表情包");
            empty.setPadding(dp(16), dp(24), dp(16), dp(24));
            grid.addView(empty);
        }
    }

    private void sendStickerNow(JsonObject sticker) {
        if (sendRequest != null) {
            Snackbar.make(binding.getRoot(), "上一条消息正在发送，请稍候", Snackbar.LENGTH_SHORT).show();
            return;
        }
        long stickerId = Jsons.longValue(sticker, "id");
        String preview = Jsons.string(sticker, "image_url");
        if (stickerId <= 0 || preview.isEmpty()) {
            Snackbar.make(binding.getRoot(), "表情信息不完整，无法发送", Snackbar.LENGTH_LONG).show();
            return;
        }
        JsonObject attachment = new JsonObject();
        attachment.addProperty("media_type", "sticker");
        attachment.addProperty("sticker_id", stickerId);
        attachment.addProperty("url", preview);
        String name = Jsons.string(sticker, "name");
        if (!name.isEmpty()) attachment.addProperty("file_name", name);
        JsonArray attachments = new JsonArray();
        attachments.add(attachment);
        JsonObject body = new JsonObject();
        body.addProperty("content", "");
        body.add("attachments", attachments);
        if (MODE_CONVERSATION.equals(mode())) {
            body.addProperty("to_user_id", resolvedConversationPeerId());
        }
        sendRequest = AppAccess.from(this).repository().post(writePath(), body, result -> {
            sendRequest = null;
            if (binding == null) return;
            if (result.isAuthenticationFailure()) { login(); return; }
            if (!result.isSuccessful()) {
                Snackbar.make(binding.getRoot(), result.message().isEmpty() ? "表情发送失败" : result.message(),
                    Snackbar.LENGTH_LONG).show();
                return;
            }
            adoptCreatedConversation(result.dataObject());
            refreshMessagesNow();
        });
    }

    private void previewSticker(JsonObject sticker) {
        String previewUrl = Jsons.string(sticker, "image_url");
        if (previewUrl.isEmpty()) return;
        JsonObject preview = new JsonObject();
        preview.addProperty("media_type", "sticker");
        preview.addProperty("url", previewUrl);
        preview.addProperty("file_name", Jsons.string(sticker, "name"));
        List<JsonObject> items = new ArrayList<>();
        items.add(preview);
        InlineMediaPreviewDialog.show(this, items, 0);
    }

    private void confirmDeleteSticker(JsonObject sticker) {
        long packId = Jsons.longValue(sticker, "pack_id");
        long stickerId = Jsons.longValue(sticker, "id");
        if (packId <= 0 || stickerId <= 0 || stickerRequest != null) return;
        new YiyunyingDialogBuilder(this)
            .setTitle("删除表情")
            .setMessage("删除后将从我的表情包中移除，已发送的历史消息不受影响。")
            .setNegativeButton("取消", null)
            .setPositiveButton("删除", (dialog, which) -> {
                binding.progress.setVisibility(View.VISIBLE);
                stickerRequest = AppAccess.from(this).repository().delete(
                    "/api/user/sticker-packs/" + packId + "/stickers/" + stickerId,
                    new JsonObject(), result -> {
                        stickerRequest = null;
                        if (binding == null) return;
                        binding.progress.setVisibility(View.INVISIBLE);
                        if (result.isAuthenticationFailure()) { login(); return; }
                        Snackbar.make(binding.getRoot(), result.isSuccessful() ? "表情已删除"
                            : (result.message().isEmpty() ? "表情删除失败" : result.message()),
                            result.isSuccessful() ? Snackbar.LENGTH_SHORT : Snackbar.LENGTH_LONG).show();
                        if (result.isSuccessful()) loadStickers();
                    });
            })
            .show();
    }

    private void showMessageActions(JsonObject message) {
        List<GlassActionDialog.Action> actions = new ArrayList<>();
        String copyText = messageCopyText(message);
        JsonObject attachment = firstAttachment(message);
        String attachmentType = Jsons.string(attachment, "media_type");
        boolean hasText = !Jsons.string(message, "content").trim().isEmpty();
        if (hasText && !copyText.isEmpty()) actions.add(action("复制文字", R.drawable.ic_content_paste, "copy", message, copyText));
        if (SecureMediaClipboard.hasCopyableMedia(message)) {
            actions.add(action("复制媒体", R.drawable.ic_file, "copy_media", message, copyText));
        }
        if ("audio".equals(attachmentType)) {
            actions.add(action("转写", R.drawable.ic_mic, "transcribe", message, copyText));
        } else if ("sticker".equals(attachmentType)) {
            actions.add(action("存表情", R.drawable.ic_album, "add_sticker", message, copyText));
        } else if (!attachmentType.isEmpty()) {
            String openLabel = "contact_card".equals(attachmentType) ? "名片"
                : (isBusinessAttachment(attachmentType) ? "详情" : "预览");
            actions.add(action(openLabel, "image".equals(attachmentType) || "video".equals(attachmentType)
                ? R.drawable.ic_album : R.drawable.ic_file, "open", message, copyText));
        }
        if (AppAccess.from(this).session().role() == Role.USER) {
            actions.add(action("转发", R.drawable.ic_send, "forward", message, copyText));
        }
        actions.add(action("引用", R.drawable.ic_chat, "quote", message, copyText));
        if (hasText) actions.add(action("翻译", R.drawable.ic_document, "translate", message, copyText));
        if (AppAccess.from(this).session().role() == Role.USER) {
            actions.add(action(boolValue(message, "is_favorite") ? "取消收藏" : "收藏", R.drawable.ic_file, "favorite", message, copyText));
        }
        if (canEditMessage(message)) actions.add(action("重编", R.drawable.ic_document, "edit", message, copyText));
        if (canRecall(message)) actions.add(action("撤回", R.drawable.ic_refresh, "recall", message, copyText));
        actions.add(action("举报", R.drawable.ic_more, "report", message, copyText));
        if (AppAccess.from(this).session().role() == Role.USER) actions.add(action("多选", R.drawable.ic_more, "more", message, copyText));
        if (!actions.isEmpty()) GlassActionDialog.showCompact(this, actions);
    }

    private void deleteSystemMessage(JsonObject message) {
        if (messageActionRequest != null || AppAccess.from(this).session().role() != Role.USER) return;
        long messageId = Jsons.longValue(message, "id");
        if (messageId <= 0) return;
        String path;
        if (MODE_CONVERSATION.equals(mode())) {
            path = "/api/user/messages/" + messageId + "/state";
        } else {
            String scope = MODE_ROOM.equals(mode()) ? "group" : "service";
            long target = MODE_ROOM.equals(mode())
                ? getIntent().getLongExtra(EXTRA_TARGET_ID, 0) : serviceSessionId;
            if (target <= 0) return;
            path = "/api/user/communication/" + scope + "/" + target + "/messages/" + messageId + "/state";
        }
        JsonObject body = new JsonObject();
        body.addProperty("action", "delete");
        messageActionRequest = AppAccess.from(this).repository().post(path, body, result -> {
            messageActionRequest = null;
            if (binding == null) return;
            if (!result.isSuccessful()) {
                Snackbar.make(binding.getRoot(), result.message().isEmpty() ? "提示删除失败" : result.message(), Snackbar.LENGTH_LONG).show();
                return;
            }
            ViewportAnchor anchor = captureHistoryViewportAnchor();
            messages.remove(messageId);
            adapter.submit(orderedMessageSnapshot());
            scheduleViewportAnchorRestore(anchor, 0L, 80L, 180L);
            Snackbar.make(binding.getRoot(), "已从当前账号的聊天记录中删除", Snackbar.LENGTH_SHORT).show();
        });
    }

    private GlassActionDialog.Action action(String label, int icon, String action, JsonObject message, String copyText) {
        return new GlassActionDialog.Action(label, icon, () -> runMessageAction(action, message, copyText));
    }

    private void runMessageAction(String action, JsonObject message, String copyText) {
        switch (action) {
            case "copy": copyMessage(copyText); break;
            case "copy_media": copyMessageMedia(message); break;
            case "open": openPrimaryMessageContent(message); break;
            case "transcribe": transcribeVoice(message); break;
            case "add_sticker": addMessageSticker(message); break;
            case "forward": chooseForwardTarget(java.util.Collections.singletonList(Jsons.longValue(message, "id"))); break;
            case "quote": quoteMessage(message); break;
            case "translate": translateMessage(message); break;
            case "favorite": toggleFavorite(message); break;
            case "edit": editMessage(message); break;
            case "recall": confirmRecall(message); break;
            case "report": reportMessage(message); break;
            default: enterSelection(message); break;
        }
    }

    private void reportMessage(JsonObject message) {
        long messageId = Jsons.longValue(message, "id");
        String targetType;
        if (MODE_ROOM.equals(mode())) targetType = "group_message";
        else if (MODE_CONVERSATION.equals(mode())) targetType = "private_message";
        else targetType = "service_message";
        String sender = firstNonEmpty(message, "sender_name", "nickname", "account");
        ContentReportDialog.show(this, targetType, messageId,
            sender.isEmpty() ? "这条消息" : sender + "发送的消息");
    }

    private JsonObject firstAttachment(JsonObject message) {
        JsonArray attachments = Jsons.array(message, "attachments");
        for (JsonElement element : attachments) {
            if (element.isJsonObject()) return element.getAsJsonObject();
        }
        return new JsonObject();
    }

    private boolean isBusinessAttachment(String type) {
        return "favorite".equals(type) || "moment_share".equals(type)
            || "red_packet".equals(type) || "transfer".equals(type)
            || "contact_card".equals(type) || "gift".equals(type) || "location".equals(type);
    }

    private void openPrimaryMessageContent(JsonObject message) {
        JsonObject attachment = firstAttachment(message);
        String type = Jsons.string(attachment, "media_type");
        if (type.isEmpty()) return;
        if (isBusinessAttachment(type)) {
            openBusinessAttachment(attachment);
            return;
        }
        if ("image".equals(type) || "video".equals(type) || "sticker".equals(type)) {
            List<JsonObject> preview = new ArrayList<>();
            preview.add(attachment);
            InlineMediaPreviewDialog.show(this, preview, 0);
            return;
        }
        JsonObject file = attachment.deepCopy();
        file.addProperty("file_url", Jsons.string(attachment, "url"));
        file.addProperty("original_name", Jsons.string(attachment, "file_name"));
        FilePreviewActivity.open(this, file);
    }

    private void transcribeVoice(JsonObject message) {
        JsonObject attachment = firstAttachment(message);
        if (!"audio".equals(Jsons.string(attachment, "media_type"))) return;
        String cached = Jsons.string(attachment, "transcript");
        if (cached.isEmpty()) cached = Jsons.string(Jsons.object(attachment, "metadata"), "transcript");
        if (!cached.isEmpty()) {
            adapter.expandTranscript(Jsons.longValue(message, "id"));
            return;
        }
        if (messageActionRequest != null) return;
        JsonObject body = new JsonObject();
        body.addProperty("message_id", Jsons.longValue(message, "id"));
        body.addProperty("attachment_id", Jsons.longValue(attachment, "id"));
        body.addProperty("audio_url", Jsons.string(attachment, "url"));
        body.addProperty("scope_type", MODE_ROOM.equals(mode()) ? "group"
            : (MODE_CONVERSATION.equals(mode()) ? "private" : "service"));
        body.addProperty("scope_id", MODE_ROOM.equals(mode())
            ? getIntent().getLongExtra(EXTRA_TARGET_ID, 0)
            : (MODE_CONVERSATION.equals(mode()) ? getIntent().getLongExtra(EXTRA_TARGET_ID, 0) : serviceSessionId));
        binding.progress.setVisibility(View.VISIBLE);
        messageActionRequest = AppAccess.from(this).repository().post("/api/user/audio/transcriptions", body, result -> {
            messageActionRequest = null;
            if (binding == null) return;
            binding.progress.setVisibility(View.INVISIBLE);
            if (!result.isSuccessful()) {
                Snackbar.make(binding.getRoot(), friendlySpeechError(result.message()), Snackbar.LENGTH_LONG).show();
                return;
            }
            String transcript = Jsons.string(result.dataObject(), "transcript");
            if (transcript.isEmpty()) transcript = "未识别到清晰语音";
            attachment.addProperty("transcript", transcript);
            adapter.expandTranscript(Jsons.longValue(message, "id"));
            Snackbar.make(binding.getRoot(), "转写完成，可在语音下方展开或收起", Snackbar.LENGTH_SHORT).show();
        });
    }

    private String friendlySpeechError(String message) {
        String detail = message == null ? "" : message.trim();
        if (detail.isEmpty()) return "语音转文字暂时不可用，请稍后重试";
        String normalized = detail.toLowerCase(java.util.Locale.ROOT);
        if (normalized.contains("stt_api_url") || normalized.contains("stt_api_key")
            || normalized.contains("faster-whisper") || normalized.contains("stt_command")
            || normalized.contains("proc_open") || normalized.contains("permission denied")
            || normalized.contains("php warning") || normalized.contains("php message")) {
            return "当前设备的离线识别不可用，网络识别也暂时无法连接";
        }
        return detail;
    }

    private void addMessageSticker(JsonObject message) {
        if (stickerRequest != null) return;
        JsonObject attachment = firstAttachment(message);
        String type = Jsons.string(attachment, "media_type");
        if (!"sticker".equals(type) && !"image".equals(type)) return;
        String imageUrl = Jsons.string(attachment, "url");
        if (imageUrl.isEmpty()) {
            Snackbar.make(binding.getRoot(), "这张图片没有可保存的地址", Snackbar.LENGTH_LONG).show();
            return;
        }
        binding.progress.setVisibility(View.VISIBLE);
        stickerRequest = AppAccess.from(this).repository().get("/api/user/sticker-packs", new LinkedHashMap<>(), result -> {
            stickerRequest = null;
            if (binding == null) return;
            if (!result.isSuccessful()) {
                binding.progress.setVisibility(View.INVISIBLE);
                Snackbar.make(binding.getRoot(), result.message().isEmpty() ? "表情包加载失败" : result.message(), Snackbar.LENGTH_LONG).show();
                return;
            }
            long packId = 0;
            for (JsonObject pack : result.objectItems()) {
                if ("我的表情".equals(Jsons.string(pack, "name"))) {
                    packId = Jsons.longValue(pack, "id");
                    break;
                }
            }
            if (packId > 0) {
                addMessageStickerToPack(packId, attachment);
                return;
            }
            JsonObject create = new JsonObject();
            create.addProperty("name", "我的表情");
            stickerRequest = AppAccess.from(this).repository().post("/api/user/sticker-packs", create, created -> {
                stickerRequest = null;
                if (binding == null) return;
                if (!created.isSuccessful()) {
                    binding.progress.setVisibility(View.INVISIBLE);
                    Snackbar.make(binding.getRoot(), created.message().isEmpty() ? "创建表情分组失败" : created.message(), Snackbar.LENGTH_LONG).show();
                    return;
                }
                addMessageStickerToPack(Jsons.longValue(created.dataObject(), "pack_id"), attachment);
            });
        });
    }

    private void addMessageStickerToPack(long packId, JsonObject attachment) {
        JsonObject body = new JsonObject();
        body.addProperty("image_url", Jsons.string(attachment, "url"));
        body.addProperty("thumbnail_url", Jsons.string(attachment, "thumbnail_url"));
        body.addProperty("name", Jsons.string(attachment, "file_name"));
        body.addProperty("width", Jsons.longValue(attachment, "width"));
        body.addProperty("height", Jsons.longValue(attachment, "height"));
        stickerRequest = AppAccess.from(this).repository().post("/api/user/sticker-packs/" + packId + "/stickers", body, result -> {
            stickerRequest = null;
            if (binding == null) return;
            binding.progress.setVisibility(View.INVISIBLE);
            Snackbar.make(binding.getRoot(), result.isSuccessful() ? "已添加到“我的表情”" : result.message(), Snackbar.LENGTH_LONG).show();
        });
    }

    private void quoteMessage(JsonObject message) {
        pendingQuote = message.deepCopy();
        binding.quotePreview.setText("引用 " + messageSender(message) + "：" + messageSnippet(message, 72));
        binding.quoteBar.setVisibility(View.VISIBLE);
        binding.messageInput.requestFocus();
        binding.messageInput.post(() -> {
            android.view.inputmethod.InputMethodManager keyboard = (android.view.inputmethod.InputMethodManager) getSystemService(Context.INPUT_METHOD_SERVICE);
            keyboard.showSoftInput(binding.messageInput, android.view.inputmethod.InputMethodManager.SHOW_IMPLICIT);
        });
    }

    private void jumpToMessage(long messageId) {
        if (adapter == null || binding == null || messageId <= 0) return;
        int position = adapter.positionOf(messageId);
        if (position < 0) {
            Snackbar.make(binding.getRoot(), "原消息不在当前已加载记录中，可在聊天记录搜索中查找", Snackbar.LENGTH_LONG).show();
            return;
        }
        binding.recycler.smoothScrollToPosition(position);
        binding.recycler.postDelayed(() -> {
            if (binding == null) return;
            RecyclerView.ViewHolder holder = binding.recycler.findViewHolderForAdapterPosition(position);
            if (holder == null) return;
            holder.itemView.animate().cancel();
            holder.itemView.setAlpha(0.42f);
            holder.itemView.animate().alpha(1f).setDuration(420L).start();
        }, 280L);
    }

    private void focusPendingMention() {
        if (pendingFocusMessageId <= 0L || adapter == null || binding == null) return;
        int position = adapter.positionOf(pendingFocusMessageId);
        if (position < 0) {
            long requested = pendingFocusMessageId;
            pendingFocusMessageId = 0L;
            loadMentionTarget(requested);
            return;
        }
        long requested = pendingFocusMessageId;
        pendingFocusMessageId = 0L;
        binding.recycler.scrollToPosition(position);
        binding.recycler.postDelayed(() -> {
            if (binding == null) return;
            jumpToMessage(requested);
            Snackbar.make(binding.getRoot(), "已定位到 @ 你的消息", Snackbar.LENGTH_SHORT).show();
        }, 180L);
    }

    private void loadMentionTarget(long messageId) {
        Map<String, String> query = new LinkedHashMap<>();
        query.put("scope_type", searchScope());
        query.put("target_id", String.valueOf(searchTargetId()));
        query.put("message_ids", String.valueOf(messageId));
        query.put("limit", "1");
        AppAccess.from(this).repository().get("/api/user/chat-search", query, result -> {
            if (binding == null) return;
            if (!result.isSuccessful() || result.items().size() == 0) {
                Snackbar.make(binding.getRoot(), "被提及的消息已撤回、删除或暂时无法读取", Snackbar.LENGTH_LONG).show();
                return;
            }
            merge(result.items());
            adapter.submit(orderedMessageSnapshot());
            pendingFocusMessageId = messageId;
            binding.recycler.post(this::focusPendingMention);
        });
    }

    @Override protected void onNewIntent(Intent intent) {
        super.onNewIntent(intent);
        String previousConversation = conversationIdentity(getIntent());
        String nextConversation = conversationIdentity(intent);
        setIntent(intent);
        pendingFocusMessageId = intent.getLongExtra(EXTRA_FOCUS_MESSAGE_ID, 0L);
        if (!previousConversation.equals(nextConversation)) {
            resetForConversationIntent();
            if (running) loadMessages();
        } else if (pendingFocusMessageId > 0L) {
            focusPendingMention();
        } else {
            refreshMessagesNow();
        }
    }

    private String conversationIdentity(Intent intent) {
        if (intent == null) return "";
        String intentMode = intent.getStringExtra(EXTRA_MODE);
        if (intentMode == null || intentMode.trim().isEmpty()) intentMode = MODE_SERVICE_USER;
        long targetId = intent.getLongExtra(EXTRA_TARGET_ID, 0L);
        long peerId = MODE_CONVERSATION.equals(intentMode)
            ? intent.getLongExtra(EXTRA_PEER_ID, 0L) : 0L;
        return intentMode + ':' + targetId + ':' + peerId;
    }

    private void resetForConversationIntent() {
        invalidateMessageRequest();
        if (roomMetadataRequest != null) {
            roomMetadataRequest.cancel();
            roomMetadataRequest = null;
        }
        roomMetadataGeneration++;
        roomKind = "group";
        roomMetadataResolved = false;
        messages.clear();
        adapter.submit(new ArrayList<>());
        lastId = 0L;
        markedReadId = 0L;
        serviceSessionId = 0L;
        incrementalPollCount = 0;
        pendingNewMessageCount = 0;
        viewportRestoreGeneration++;
        userHoldingHistory = false;
        firstLoad = true;
        resolvedPeerId = Math.max(0L, getIntent().getLongExtra(EXTRA_PEER_ID, 0L));
        searchQuery = "";
        searchContentFilter = "all";
        normalTitle = getIntent().getStringExtra(EXTRA_TITLE);
        if (normalTitle == null || normalTitle.trim().isEmpty()) normalTitle = "聊天";
        xyz.jjmxg.yiyunying.core.RuntimeLanguage.setDynamicToolbarTitle(binding.toolbar, normalTitle);
        binding.emptyText.setVisibility(View.GONE);
        binding.progress.setVisibility(View.VISIBLE);
        applyChatBackground();
        refreshRoomPresentation();
        loadRoomMetadata();
        renderNewMessageIndicator();
    }

    private void clearQuote() {
        pendingQuote = null;
        if (binding != null) {
            binding.quotePreview.setText("");
            binding.quoteBar.setVisibility(View.GONE);
        }
    }

    private String messageSender(JsonObject message) {
        String sender = first(message, "sender_name", "nickname", "account");
        return "未命名".equals(sender) ? "消息发送者" : sender;
    }

    private String messageSnippet(JsonObject message, int maximum) {
        String value = Jsons.string(message, "content").replace('\n', ' ').trim();
        if (value.isEmpty()) {
            int attachments = Jsons.array(message, "attachments").size();
            value = attachments > 0 ? "[" + attachments + " 个附件]" : "[聊天记录]";
        }
        return value.length() > maximum ? value.substring(0, maximum) + "…" : value;
    }

    private void translateMessage(JsonObject message) {
        String content = Jsons.string(message, "content").trim();
        if (content.isEmpty()) return;
        Intent intent = new Intent(Intent.ACTION_PROCESS_TEXT);
        intent.setType("text/plain");
        intent.putExtra(Intent.EXTRA_PROCESS_TEXT, content);
        intent.putExtra(Intent.EXTRA_PROCESS_TEXT_READONLY, true);
        try {
            if (getPackageManager().queryIntentActivities(intent, 0).isEmpty()) throw new ActivityNotFoundException();
            startActivity(Intent.createChooser(intent, "选择翻译工具"));
        } catch (ActivityNotFoundException exception) {
            new YiyunyingDialogBuilder(this)
                .setTitle("当前设备没有可用翻译工具")
                .setMessage(content)
                .setPositiveButton("复制后翻译", (dialog, which) -> copyMessage(content))
                .setNegativeButton("关闭", null)
                .show();
        }
    }

    private void toggleFavorite(JsonObject message) {
        if (messageActionRequest != null) return;
        JsonObject body = new JsonObject();
        boolean currentlyFavorite = boolValue(message, "is_favorite");
        String path;
        if (MODE_CONVERSATION.equals(mode())) {
            path = "/api/user/messages/" + Jsons.longValue(message, "id") + "/state";
            body.addProperty("action", "favorite");
        } else {
            String scope = MODE_ROOM.equals(mode()) ? "group" : "service";
            long target = MODE_ROOM.equals(mode()) ? getIntent().getLongExtra(EXTRA_TARGET_ID, 0) : serviceSessionId;
            if (target <= 0) {
                Snackbar.make(binding.getRoot(), "当前会话尚未建立，暂时不能收藏", Snackbar.LENGTH_LONG).show();
                return;
            }
            path = "/api/user/communication/" + scope + "/" + target + "/messages/"
                + Jsons.longValue(message, "id") + "/state";
            body.addProperty("action", currentlyFavorite ? "unfavorite" : "favorite");
        }
        messageActionRequest = AppAccess.from(this).repository().post(path, body, result -> {
                messageActionRequest = null;
                if (binding == null) return;
                if (!result.isSuccessful()) {
                    Snackbar.make(binding.getRoot(), result.message().isEmpty() ? "收藏操作失败" : result.message(), Snackbar.LENGTH_LONG).show();
                    return;
                }
                boolean favorite = boolValue(result.dataObject(), "is_favorite");
                message.addProperty("is_favorite", favorite);
                int position = adapter.positionOf(Jsons.longValue(message, "id"));
                if (position >= 0) adapter.notifyItemChanged(position);
                Snackbar.make(binding.getRoot(), favorite ? "消息已收藏，可在“我的收藏”中查看" : "已取消收藏", Snackbar.LENGTH_SHORT).show();
            });
    }

    private void selectMessagesForForward(JsonObject initial) {
        List<JsonObject> selectable = new ArrayList<>();
        for (JsonObject item : messages.values()) {
            if (Jsons.longValue(item, "id") > 0 && !"recall".equals(Jsons.string(item, "content_type"))) selectable.add(item);
        }
        if (selectable.size() > 50) selectable = new ArrayList<>(selectable.subList(selectable.size() - 50, selectable.size()));
        if (selectable.isEmpty()) return;
        String[] labels = new String[selectable.size()];
        boolean[] checked = new boolean[selectable.size()];
        long initialId = Jsons.longValue(initial, "id");
        for (int index = 0; index < selectable.size(); index++) {
            JsonObject item = selectable.get(index);
            String sender = Jsons.string(item, "nickname");
            if (sender.isEmpty()) sender = Jsons.string(item, "account");
            if (sender.isEmpty()) sender = "消息发送者";
            String content = Jsons.string(item, "content");
            if (content.isEmpty()) content = "[" + DisplayText.fieldValue("content_type", item.get("content_type")) + "]";
            labels[index] = sender + "：" + (content.length() > 42 ? content.substring(0, 42) + "…" : content);
            checked[index] = Jsons.longValue(item, "id") == initialId;
        }
        List<JsonObject> source = selectable;
        new YiyunyingDialogBuilder(this).setTitle("选择要转发的聊天记录（最多 50 条）")
            .setMultiChoiceItems(labels, checked, (dialog, which, enabled) -> checked[which] = enabled)
            .setPositiveButton("下一步", (dialog, which) -> {
                List<Long> ids = new ArrayList<>();
                for (int index = 0; index < checked.length; index++) if (checked[index]) ids.add(Jsons.longValue(source.get(index), "id"));
                if (ids.isEmpty()) Snackbar.make(binding.getRoot(), "请至少选择一条消息", Snackbar.LENGTH_LONG).show();
                else chooseForwardTarget(ids);
            }).setNegativeButton("取消", null).show();
    }

    private void chooseForwardTarget(List<Long> ids) {
        String[] labels = {"好友私聊", "群聊或聊天室", "发布为论坛新帖", "评论到已有帖子", "在线客服"};
        String[] types = {"private", "group", "forum_post", "forum", "service"};
        new YiyunyingDialogBuilder(this).setTitle("转发到")
            .setItems(labels, (dialog, which) -> {
                if ("service".equals(types[which])) chooseForwardPrivacy(ids, "service", java.util.Collections.singletonList(0L));
                else loadForwardTargets(ids, types[which]);
            }).setNegativeButton("取消", null).show();
    }

    private void loadForwardTargets(List<Long> ids, String targetType) {
        if (forwardRequest != null) return;
        String path = "private".equals(targetType) ? "/api/user/friends"
            : ("group".equals(targetType) ? "/api/user/chat-rooms"
            : ("forum_post".equals(targetType) ? "/api/user/forum-plates" : "/api/user/forum-posts"));
        LinkedHashMap<String, String> query = new LinkedHashMap<>();
        query.put("limit", "500");
        binding.progress.setVisibility(View.VISIBLE);
        forwardRequest = AppAccess.from(this).repository().get(path, query, result -> {
            forwardRequest = null;
            if (binding == null) return;
            binding.progress.setVisibility(View.INVISIBLE);
            if (!result.isSuccessful()) {
                Snackbar.make(binding.getRoot(), result.message().isEmpty() ? "转发目标加载失败" : result.message(), Snackbar.LENGTH_LONG).show();
                return;
            }
            List<JsonObject> targets = new ArrayList<>();
            for (JsonObject item : result.objectItems()) {
                if (!"group".equals(targetType) || boolValue(item, "joined")) targets.add(item);
            }
            if (targets.isEmpty()) {
                Snackbar.make(binding.getRoot(), "没有可用的转发目标", Snackbar.LENGTH_LONG).show();
                return;
            }
            targets.sort((left, right) -> {
                int frequency = Integer.compare(forwardFrequency(targetType, targetId(right, targetType)),
                    forwardFrequency(targetType, targetId(left, targetType)));
                return frequency != 0 ? frequency : forwardTargetLabel(left, targetType)
                    .compareToIgnoreCase(forwardTargetLabel(right, targetType));
            });
            showForwardTargetPicker(ids, targetType, targets);
        });
    }

    private void showForwardTargetPicker(List<Long> ids, String targetType, List<JsonObject> targets) {
        BottomSheetDialog dialog = new BottomSheetDialog(this);
        LinearLayout root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setPadding(dp(18), dp(8), dp(18), dp(14));
        root.setBackgroundColor(getColor(R.color.surface));

        View handle = new View(this);
        android.graphics.drawable.GradientDrawable handleBackground = new android.graphics.drawable.GradientDrawable();
        handleBackground.setColor(getColor(R.color.outline_variant));
        handleBackground.setCornerRadius(dp(2));
        handle.setBackground(handleBackground);
        LinearLayout.LayoutParams handleParams = new LinearLayout.LayoutParams(dp(38), dp(4));
        handleParams.gravity = Gravity.CENTER_HORIZONTAL;
        handleParams.bottomMargin = dp(12);
        root.addView(handle, handleParams);

        LinearLayout headingRow = new LinearLayout(this);
        headingRow.setOrientation(LinearLayout.HORIZONTAL);
        headingRow.setGravity(Gravity.CENTER_VERTICAL);
        TextView heading = new TextView(this);
        heading.setText("forum_post".equals(targetType) ? "选择发布板块" : "选择转发目标");
        heading.setTextColor(getColor(R.color.on_surface));
        heading.setTextSize(20f);
        heading.setTypeface(heading.getTypeface(), android.graphics.Typeface.BOLD);
        headingRow.addView(heading, new LinearLayout.LayoutParams(0, dp(44), 1f));
        TextView selectedCount = new TextView(this);
        selectedCount.setTextColor(ThemeColors.primary(this));
        selectedCount.setTextSize(13f);
        selectedCount.setGravity(Gravity.CENTER_VERTICAL | Gravity.END);
        headingRow.addView(selectedCount, new LinearLayout.LayoutParams(dp(92), dp(44)));
        root.addView(headingRow, new LinearLayout.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));

        TextInputLayout searchLayout = new TextInputLayout(this);
        searchLayout.setBoxBackgroundMode(TextInputLayout.BOX_BACKGROUND_OUTLINE);
        searchLayout.setHint("forum_post".equals(targetType) ? "搜索论坛板块" : "搜索好友、群聊或帖子");
        searchLayout.setStartIconDrawable(R.drawable.ic_search);
        TextInputEditText search = new TextInputEditText(this);
        search.setSingleLine(true);
        search.setImeOptions(EditorInfo.IME_ACTION_SEARCH);
        searchLayout.addView(search, new LinearLayout.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT, dp(54)));
        LinearLayout.LayoutParams searchParams = new LinearLayout.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT);
        searchParams.bottomMargin = dp(8);
        root.addView(searchLayout, searchParams);

        TextView frequentTitle = new TextView(this);
        frequentTitle.setText("常转发");
        frequentTitle.setTextColor(getColor(R.color.on_surface_variant));
        frequentTitle.setTextSize(13f);
        frequentTitle.setPadding(0, dp(4), 0, dp(6));
        root.addView(frequentTitle);
        android.widget.HorizontalScrollView frequentScroll = new android.widget.HorizontalScrollView(this);
        frequentScroll.setHorizontalScrollBarEnabled(false);
        LinearLayout frequentRow = new LinearLayout(this);
        frequentRow.setOrientation(LinearLayout.HORIZONTAL);
        frequentScroll.addView(frequentRow);
        LinearLayout.LayoutParams frequentParams = new LinearLayout.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT, dp(48));
        frequentParams.bottomMargin = dp(6);
        root.addView(frequentScroll, frequentParams);

        LinkedHashSet<Long> selected = new LinkedHashSet<>();
        RecyclerView list = new RecyclerView(this);
        list.setLayoutManager(new LinearLayoutManager(this));
        list.setHasFixedSize(true);
        list.setItemAnimator(null);
        ForwardTargetAdapter adapter = new ForwardTargetAdapter(targetType, targets, selected);
        list.setAdapter(adapter);
        root.addView(list, new LinearLayout.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT, 0, 1f));

        Runnable[] refreshFrequent = new Runnable[1];
        Runnable updateSelection = () -> {
            selectedCount.setText(selected.isEmpty() ? "未选择" : "已选 " + selected.size() + " 个");
            if (refreshFrequent[0] != null) refreshFrequent[0].run();
        };
        adapter.setSelectionChangedListener(updateSelection);
        refreshFrequent[0] = () -> {
            frequentRow.removeAllViews();
            int added = 0;
            for (JsonObject target : targets) {
                long id = targetId(target, targetType);
                if (id <= 0 || forwardFrequency(targetType, id) <= 0 || added >= 10) continue;
                MaterialButton quick = new MaterialButton(this, null,
                    com.google.android.material.R.attr.materialButtonOutlinedStyle);
                quick.setText(forwardTargetLabel(target, targetType));
                quick.setTextSize(12f);
                quick.setAllCaps(false);
                quick.setMaxLines(1);
                quick.setEllipsize(android.text.TextUtils.TruncateAt.END);
                quick.setCheckable(true);
                quick.setChecked(selected.contains(id));
                quick.setOnClickListener(view -> adapter.toggle(id));
                LinearLayout.LayoutParams quickParams = new LinearLayout.LayoutParams(dp(132), dp(44));
                quickParams.setMarginEnd(dp(8));
                frequentRow.addView(quick, quickParams);
                added++;
            }
            frequentTitle.setVisibility(added == 0 ? View.GONE : View.VISIBLE);
            frequentScroll.setVisibility(added == 0 ? View.GONE : View.VISIBLE);
        };
        updateSelection.run();

        search.addTextChangedListener(new TextWatcher() {
            @Override public void beforeTextChanged(CharSequence value, int start, int count, int after) { }
            @Override public void onTextChanged(CharSequence value, int start, int before, int count) {
                adapter.filter(value == null ? "" : value.toString());
            }
            @Override public void afterTextChanged(Editable value) { }
        });

        LinearLayout actionRow = new LinearLayout(this);
        actionRow.setOrientation(LinearLayout.HORIZONTAL);
        actionRow.setGravity(Gravity.CENTER_VERTICAL);
        actionRow.setPadding(0, dp(10), 0, 0);
        MaterialButton cancel = new MaterialButton(this, null,
            com.google.android.material.R.attr.materialButtonOutlinedStyle);
        cancel.setText("取消");
        cancel.setAllCaps(false);
        cancel.setOnClickListener(view -> dialog.dismiss());
        MaterialButton next = new MaterialButton(this);
        next.setText("forum_post".equals(targetType) ? "下一步" : "选择接收人");
        next.setIconResource(R.drawable.ic_arrow_right);
        next.setIconGravity(MaterialButton.ICON_GRAVITY_TEXT_END);
        next.setAllCaps(false);
        next.setOnClickListener(view -> {
            if (selected.isEmpty()) {
                Snackbar.make(root, "请至少选择一个接收目标", Snackbar.LENGTH_LONG).show();
                return;
            }
            dialog.dismiss();
            if ("forum_post".equals(targetType)) {
                showForumPostForwardComposer(ids, selected.iterator().next());
            } else {
                chooseForwardPrivacy(ids, targetType, new ArrayList<>(selected));
            }
        });
        LinearLayout.LayoutParams actionParams = new LinearLayout.LayoutParams(0, dp(52), 1f);
        actionParams.setMarginEnd(dp(8));
        actionRow.addView(cancel, actionParams);
        LinearLayout.LayoutParams nextParams = new LinearLayout.LayoutParams(0, dp(52), 1.45f);
        actionRow.addView(next, nextParams);
        root.addView(actionRow, new LinearLayout.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));

        dialog.setContentView(root, new ViewGroup.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT));
        GlassBottomSheet.prepare(dialog, this, 0.86f, true);
        dialog.show();
    }
    private void toggleForwardTarget(Set<Long> selected, long id, boolean enabled, String targetType) {
        if (!enabled) { selected.remove(id); return; }
        if ("forum_post".equals(targetType)) {
            selected.clear();
            selected.add(id);
            return;
        }
        if (selected.size() >= 10) {
            Snackbar.make(binding.getRoot(), "一次最多转发给 10 个目标", Snackbar.LENGTH_LONG).show();
            return;
        }
        selected.add(id);
    }

    private long targetId(JsonObject target, String targetType) {
        return "private".equals(targetType) ? Jsons.longValue(target, "user_id") : Jsons.longValue(target, "id");
    }

    private String forwardTargetLabel(JsonObject target, String targetType) {
        if ("private".equals(targetType)) return first(target, "nickname", "account") + " · " + Jsons.string(target, "account");
        String prefix = "forum_post".equals(targetType) ? "板块 " : "编号 ";
        return first(target, "name", "title") + " · " + prefix + Jsons.longValue(target, "id");
    }

    private int forwardFrequency(String type, long id) {
        return getSharedPreferences("forward_frequency", MODE_PRIVATE).getInt(type + ":" + id, 0);
    }

    private void increaseForwardFrequency(String type, long id) {
        android.content.SharedPreferences preferences = getSharedPreferences("forward_frequency", MODE_PRIVATE);
        String key = type + ":" + id;
        preferences.edit().putInt(key, Math.min(100000, preferences.getInt(key, 0) + 1)).apply();
    }

    private void showForumPostForwardComposer(List<Long> ids, long plateId) {
        LinearLayout form = new LinearLayout(this);
        form.setOrientation(LinearLayout.VERTICAL);
        form.setPadding(dp(20), dp(4), dp(20), 0);

        TextInputLayout titleLayout = new TextInputLayout(this);
        titleLayout.setHint("帖子标题");
        TextInputEditText title = new TextInputEditText(this);
        title.setSingleLine(true);
        title.setText("聊天记录");
        titleLayout.addView(title, new LinearLayout.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));
        form.addView(titleLayout, new LinearLayout.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));

        TextInputLayout introLayout = new TextInputLayout(this);
        introLayout.setHint("补充说明（可选）");
        TextInputEditText intro = new TextInputEditText(this);
        intro.setMinLines(3);
        intro.setMaxLines(6);
        intro.setGravity(Gravity.TOP);
        introLayout.addView(intro, new LinearLayout.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));
        LinearLayout.LayoutParams introParams = new LinearLayout.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT);
        introParams.topMargin = dp(12);
        form.addView(introLayout, introParams);

        AlertDialog dialog = new YiyunyingDialogBuilder(this)
            .setTitle("发布论坛新帖")
            .setView(form)
            .setPositiveButton("下一步", null)
            .setNegativeButton("取消", null)
            .create();
        dialog.setOnShowListener(ignored -> dialog.getButton(AlertDialog.BUTTON_POSITIVE).setOnClickListener(view -> {
            String titleText = title.getText() == null ? "" : title.getText().toString().trim();
            if (titleText.isEmpty()) {
                titleLayout.setError("请填写帖子标题");
                return;
            }
            JsonObject extras = new JsonObject();
            extras.addProperty("forum_title", titleText);
            extras.addProperty("forum_content", intro.getText() == null ? "" : intro.getText().toString().trim());
            JsonArray forumTags = new JsonArray();
            forumTags.add("聊天记录");
            extras.add("forum_tags", forumTags);
            dialog.dismiss();
            chooseForwardPrivacy(ids, "forum_post", java.util.Collections.singletonList(plateId), extras);
        }));
        dialog.show();
    }

    private void chooseForwardPrivacy(List<Long> ids, String targetType, List<Long> targetIds) {
        chooseForwardPrivacy(ids, targetType, targetIds, new JsonObject());
    }

    private void chooseForwardPrivacy(
        List<Long> ids, String targetType, List<Long> targetIds, JsonObject extras
    ) {
        if (MODE_SERVICE_USER.equals(mode()) || MODE_SERVICE_ADMIN.equals(mode()) || "service".equals(targetType)) {
            postForwardBatch(ids, targetType, targetIds, "none", java.util.Collections.emptyList(), extras, 0, 0);
            return;
        }
        String[] modes = {"正常转发", "只隐藏自己", "只隐藏对方", "全部隐藏"};
        new YiyunyingDialogBuilder(this)
            .setTitle("转发身份显示")
            .setItems(modes, (dialog, which) -> {
                if (which == 0) postForwardBatch(ids, targetType, targetIds, "none", java.util.Collections.emptyList(), extras, 0, 0);
                else if (which == 3) postForwardBatch(ids, targetType, targetIds, "full", java.util.Collections.emptyList(), extras, 0, 0);
                else postForwardBatch(ids, targetType, targetIds, "selected",
                    forwardAnonymousKeys(ids, which == 1, which == 2), extras, 0, 0);
            })
            .setNegativeButton("取消", null)
            .show();
    }

    private List<String> forwardAnonymousKeys(List<Long> ids, boolean hideSelf, boolean hideOthers) {
        List<String> anonymous = new ArrayList<>();
        Set<Long> selected = new LinkedHashSet<>(ids);
        String self = "user:" + AppAccess.from(this).session().actorId();
        for (JsonObject item : messages.values()) {
            if (!selected.contains(Jsons.longValue(item, "id"))) continue;
            String key = forwardSenderKey(item);
            if (!key.startsWith("user:")) continue;
            if ((hideSelf && key.equals(self)) || (hideOthers && !key.equals(self))) {
                if (!anonymous.contains(key)) anonymous.add(key);
            }
        }
        return anonymous;
    }

    private String forwardSenderKey(JsonObject item) {
        String type = Jsons.string(item, "sender_type");
        if (type.isEmpty()) type = "user";
        long id = Jsons.longValue(item, "sender_id");
        if (id <= 0) id = Jsons.longValue(item, "user_id");
        if (id > 0) return type + ":" + id;
        return type + ":name:" + messageSender(item).toLowerCase(java.util.Locale.CHINA);
    }

    private void postForwardBatch(
        List<Long> ids,
        String targetType,
        List<Long> targetIds,
        String anonymityMode,
        List<String> anonymousSenderKeys,
        JsonObject extras,
        int index,
        int success
    ) {
        if (index >= targetIds.size()) {
            binding.progress.setVisibility(View.INVISIBLE);
            String resultText = "forum_post".equals(targetType)
                ? (success > 0 ? "已发布为论坛新帖" : "论坛新帖发布失败")
                : "已成功转发给 " + success + " 个目标";
            Snackbar.make(binding.getRoot(), resultText, Snackbar.LENGTH_LONG).show();
            return;
        }
        if (forwardRequest != null) return;
        long targetId = targetIds.get(index);
        boolean serviceTransfer = MODE_SERVICE_USER.equals(mode()) || MODE_SERVICE_ADMIN.equals(mode())
            || "service".equals(targetType);
        final String effectiveAnonymityMode = serviceTransfer ? "none" : anonymityMode;
        final List<String> effectiveAnonymousSenderKeys = serviceTransfer
            ? java.util.Collections.emptyList() : anonymousSenderKeys;
        long sourceId = MODE_SERVICE_USER.equals(mode()) ? serviceSessionId : getIntent().getLongExtra(EXTRA_TARGET_ID, 0);
        if (sourceId <= 0) {
            Snackbar.make(binding.getRoot(), "当前会话尚未建立，不能转发", Snackbar.LENGTH_LONG).show();
            return;
        }
        JsonObject body = new JsonObject();
        body.addProperty("source_type", MODE_ROOM.equals(mode()) ? "group" : (MODE_CONVERSATION.equals(mode()) ? "private" : "service"));
        body.addProperty("source_id", sourceId);
        body.addProperty("target_type", targetType);
        body.addProperty("target_id", targetId);
        body.addProperty("anonymity_mode", effectiveAnonymityMode);
        JsonArray anonymous = new JsonArray();
        for (String key : effectiveAnonymousSenderKeys) anonymous.add(key);
        body.add("anonymous_sender_keys", anonymous);
        JsonArray messageIds = new JsonArray();
        for (Long id : ids) messageIds.add(id);
        body.add("message_ids", messageIds);
        JsonArray tags = new JsonArray(); tags.add("聊天记录"); body.add("tags", tags);
        for (Map.Entry<String, JsonElement> entry : extras.entrySet()) {
            body.add(entry.getKey(), entry.getValue());
        }
        binding.progress.setVisibility(View.VISIBLE);
        forwardRequest = AppAccess.from(this).repository().post("/api/user/message-forwards", body, result -> {
            forwardRequest = null;
            if (binding == null) return;
            int completed = success;
            if (result.isSuccessful()) {
                completed++;
                increaseForwardFrequency(targetType, targetId);
            }
            postForwardBatch(ids, targetType, targetIds, effectiveAnonymityMode,
                effectiveAnonymousSenderKeys, extras, index + 1, completed);
        });
    }

    private static String first(JsonObject item, String... keys) {
        for (String key : keys) { String value = Jsons.string(item, key); if (!value.isEmpty()) return value; }
        return "未命名";
    }

    private static String firstNonEmpty(JsonObject item, String... keys) {
        for (String key : keys) {
            String value = Jsons.string(item, key).trim();
            if (!value.isEmpty()) return value;
        }
        return "";
    }

    private static String valueOr(String value, String fallback) {
        return value == null || value.trim().isEmpty() ? fallback : value.trim();
    }

    private String messageCopyText(JsonObject message) {
        return Jsons.string(message, "content").trim();
    }

    private void copyMessage(String text) {
        ClipboardManager clipboard = (ClipboardManager) getSystemService(Context.CLIPBOARD_SERVICE);
        clipboard.setPrimaryClip(ClipData.newPlainText("消息内容", text));
        Snackbar.make(binding.getRoot(), "消息内容已复制", Snackbar.LENGTH_SHORT).show();
    }

    private void copyMessageMedia(JsonObject message) {
        Snackbar.make(binding.getRoot(), "正在准备媒体文件…", Snackbar.LENGTH_SHORT).show();
        SecureMediaClipboard.copyMessageMedia(this, message, (success, count, detail) -> {
            if (binding == null) return;
            String text = success
                ? "已复制 " + count + " 个媒体文件，可直接粘贴发送"
                : (detail == null || detail.trim().isEmpty() ? "媒体文件复制失败" : detail);
            Snackbar.make(binding.getRoot(), text, success ? Snackbar.LENGTH_SHORT : Snackbar.LENGTH_LONG).show();
        });
    }

    private boolean canRecall(JsonObject message) {
        if (MODE_SERVICE_USER.equals(mode()) || MODE_SERVICE_ADMIN.equals(mode())) return false;
        if ("recall".equals(Jsons.string(message, "content_type")) || "system".equals(Jsons.string(message, "sender_type"))) return false;
        if (MODE_ROOM.equals(mode()) && AppAccess.from(this).session().role() == Role.ADMIN) return true;
        return boolValue(message, "can_recall");
    }

    private boolean canEditMessage(JsonObject message) {
        if (AppAccess.from(this).session().role() != Role.USER) return false;
        if (!MODE_CONVERSATION.equals(mode()) && !MODE_ROOM.equals(mode())) return false;
        if (!"user".equals(Jsons.string(message, "sender_type"))) return false;
        if (!"text".equals(Jsons.string(message, "content_type"))) return false;
        if (Jsons.string(message, "content").trim().isEmpty() || boolValue(message, "recalled")) return false;
        long senderId = Jsons.longValue(message, "sender_id");
        if (senderId <= 0) senderId = Jsons.longValue(message, "user_id");
        return senderId > 0 && senderId == AppAccess.from(this).session().actorId();
    }

    private void editMessage(JsonObject message) {
        if (!canEditMessage(message) || messageActionRequest != null) return;
        LinearLayout form = new LinearLayout(this);
        form.setOrientation(LinearLayout.VERTICAL);
        form.setPadding(dp(20), dp(4), dp(20), dp(8));
        form.addView(editSectionLabel("原消息"));
        form.addView(editContentCard(Jsons.string(message, "content"), false));

        TextInputLayout editorLayout = new TextInputLayout(this);
        editorLayout.setBoxBackgroundMode(TextInputLayout.BOX_BACKGROUND_OUTLINE);
        editorLayout.setHint("修改后内容");
        LinearLayout.LayoutParams editorLayoutParams = new LinearLayout.LayoutParams(
            LinearLayout.LayoutParams.MATCH_PARENT, LinearLayout.LayoutParams.WRAP_CONTENT);
        editorLayoutParams.topMargin = dp(14);
        editorLayout.setLayoutParams(editorLayoutParams);
        TextInputEditText editor = new TextInputEditText(editorLayout.getContext());
        editor.setMinLines(3);
        editor.setMaxLines(10);
        editor.setText(Jsons.string(message, "content"));
        editor.setSelection(editor.length());
        editor.setGravity(Gravity.TOP | Gravity.START);
        SafeTextInput.attach(editorLayout, editor);
        form.addView(editorLayout);
        TextView note = editSectionLabel("保存后会显示“已编辑”，双方均可查看历史版本");
        note.setTextColor(getColor(R.color.on_surface_variant));
        note.setTextSize(12);
        form.addView(note);
        androidx.appcompat.app.AlertDialog dialog = new YiyunyingDialogBuilder(this)
            .setTitle("重新编辑消息")
            .setView(form)
            .setNegativeButton("取消", null)
            .setPositiveButton("保存", null)
            .create();
        dialog.setOnShowListener(ignored -> dialog.getButton(AlertDialog.BUTTON_POSITIVE).setOnClickListener(view -> {
            String content = editor.getText() == null ? "" : editor.getText().toString().trim();
            if (content.isEmpty()) {
                editor.setError("消息内容不能为空");
                return;
            }
            if (content.equals(Jsons.string(message, "content"))) {
                editor.setError("内容没有发生变化");
                return;
            }
            JsonObject body = new JsonObject();
            body.addProperty("content", content);
            long messageId = Jsons.longValue(message, "id");
            String path = MODE_ROOM.equals(mode())
                ? "/api/user/chat-rooms/" + getIntent().getLongExtra(EXTRA_TARGET_ID, 0) + "/messages/" + messageId
                : "/api/user/messages/" + messageId;
            dialog.getButton(AlertDialog.BUTTON_POSITIVE).setEnabled(false);
            messageActionRequest = AppAccess.from(this).repository().put(path, body, result -> {
                messageActionRequest = null;
                if (binding == null) return;
                dialog.getButton(AlertDialog.BUTTON_POSITIVE).setEnabled(true);
                if (!result.isSuccessful()) {
                    editor.setError(result.message().isEmpty() ? "重新编辑失败" : result.message());
                    return;
                }
                JsonObject data = result.dataObject();
                message.addProperty("content", Jsons.string(data, "content"));
                message.addProperty("edited", true);
                message.addProperty("edit_count", Jsons.longValue(data, "edit_count"));
                message.addProperty("edited_at", Jsons.string(data, "edited_at"));
                int position = adapter.positionOf(messageId);
                if (position >= 0) adapter.notifyItemChanged(position);
                dialog.dismiss();
                Snackbar.make(binding.getRoot(), "消息已重新编辑", Snackbar.LENGTH_SHORT).show();
            });
        }));
        dialog.setOnDismissListener(ignored -> {
            if (messageActionRequest != null) {
                messageActionRequest.cancel();
                messageActionRequest = null;
            }
        });
        dialog.show();
        editor.requestFocus();
    }

    private void showEditHistory(JsonObject message) {
        if (messageActionRequest != null) return;
        long messageId = Jsons.longValue(message, "id");
        String path = MODE_ROOM.equals(mode())
            ? "/api/user/chat-rooms/" + getIntent().getLongExtra(EXTRA_TARGET_ID, 0) + "/messages/" + messageId + "/edits"
            : "/api/user/messages/" + messageId + "/edits";
        binding.progress.setVisibility(View.VISIBLE);
        messageActionRequest = AppAccess.from(this).repository().get(path, new LinkedHashMap<>(), result -> {
            messageActionRequest = null;
            if (binding == null) return;
            binding.progress.setVisibility(View.INVISIBLE);
            if (!result.isSuccessful()) {
                Snackbar.make(binding.getRoot(), result.message().isEmpty() ? "编辑记录加载失败" : result.message(), Snackbar.LENGTH_LONG).show();
                return;
            }
            JsonObject data = result.dataObject();
            LinearLayout list = new LinearLayout(this);
            list.setOrientation(LinearLayout.VERTICAL);
            list.setPadding(dp(16), dp(4), dp(16), dp(16));
            int version = 1;
            for (JsonElement element : Jsons.array(data, "items")) {
                if (!element.isJsonObject()) continue;
                JsonObject item = element.getAsJsonObject();
                list.addView(editHistoryCard("版本 " + version++ + " · " + Jsons.string(item, "created_at"),
                    Jsons.string(item, "old_content"), Jsons.string(item, "new_content"), false));
            }
            list.addView(editHistoryCard("当前版本", "", Jsons.string(data, "current_content"), true));
            ScrollView scroll = new ScrollView(this);
            scroll.addView(list);
            new YiyunyingDialogBuilder(this)
                .setTitle("消息编辑记录")
                .setView(scroll)
                .setPositiveButton("关闭", null)
                .show();
        });
    }

    private TextView editSectionLabel(String text) {
        TextView label = new TextView(this);
        label.setText(text);
        label.setTextColor(getColor(R.color.on_surface_variant));
        label.setTextSize(13);
        label.setPadding(dp(2), dp(8), dp(2), dp(6));
        return label;
    }

    private MaterialCardView editContentCard(String content, boolean current) {
        MaterialCardView card = new MaterialCardView(this);
        card.setRadius(dp(8));
        card.setStrokeWidth(dp(1));
        card.setStrokeColor(current ? ThemeColors.primary(this) : getColor(R.color.outline));
        card.setCardBackgroundColor(current ? ThemeColors.primaryContainer(this) : getColor(R.color.surface_container_high));
        TextView value = new TextView(this);
        value.setText(content == null || content.isEmpty() ? "（空）" : content);
        value.setTextColor(getColor(current ? R.color.on_primary_container : R.color.on_surface));
        value.setTextSize(14);
        value.setTextIsSelectable(true);
        value.setLineSpacing(0f, 1.15f);
        value.setPadding(dp(12), dp(10), dp(12), dp(10));
        card.addView(value);
        return card;
    }

    private MaterialCardView editHistoryCard(String header, String oldContent, String newContent, boolean current) {
        MaterialCardView card = new MaterialCardView(this);
        card.setRadius(dp(8));
        card.setStrokeWidth(dp(1));
        card.setStrokeColor(current ? ThemeColors.primary(this) : getColor(R.color.outline));
        card.setCardBackgroundColor(getColor(R.color.surface_container));
        LinearLayout body = new LinearLayout(this);
        body.setOrientation(LinearLayout.VERTICAL);
        body.setPadding(dp(12), dp(9), dp(12), dp(11));
        TextView title = editSectionLabel(header);
        title.setTextColor(current ? ThemeColors.primary(this) : getColor(R.color.on_surface));
        title.setTextSize(14);
        body.addView(title);
        if (!current) {
            body.addView(editSectionLabel("修改前"));
            body.addView(editContentCard(oldContent, false));
            body.addView(editSectionLabel("修改后"));
        }
        body.addView(editContentCard(newContent, current));
        card.addView(body);
        LinearLayout.LayoutParams params = new LinearLayout.LayoutParams(
            LinearLayout.LayoutParams.MATCH_PARENT, LinearLayout.LayoutParams.WRAP_CONTENT);
        params.bottomMargin = dp(10);
        card.setLayoutParams(params);
        return card;
    }

    private boolean boolValue(JsonObject object, String key) {
        try { return object.has(key) && !object.get(key).isJsonNull() && object.get(key).getAsBoolean(); }
        catch (RuntimeException ignored) { return false; }
    }

    private double doubleValue(JsonObject object, String key, double fallback) {
        try { return object.has(key) && !object.get(key).isJsonNull() ? object.get(key).getAsDouble() : fallback; }
        catch (RuntimeException ignored) { return fallback; }
    }

    private void confirmRecall(JsonObject message) {
        android.content.SharedPreferences preferences = getSharedPreferences("chat_ui", MODE_PRIVATE);
        String savedSuffix = preferences.getString("recall_suffix", "并坏笑了一下");
        if (preferences.getBoolean("recall_notice_explained", false)) {
            recallMessage(message, recallNotice(savedSuffix));
            return;
        }
        android.widget.EditText notice = new android.widget.EditText(this);
        notice.setHint("提示后缀，最多 6 个字");
        notice.setFilters(new android.text.InputFilter[]{new android.text.InputFilter.LengthFilter(6)});
        notice.setText(savedSuffix);
        notice.setSelectAllOnFocus(true);
        new YiyunyingDialogBuilder(this)
            .setTitle("首次使用撤回")
            .setMessage("撤回后，原文字会回到输入框。这里设置固定提示后缀，以后可在“设置”中修改。")
            .setView(notice)
            .setPositiveButton("确认撤回", (dialog, which) -> {
                String suffix = notice.getText() == null ? "" : notice.getText().toString().trim();
                preferences.edit().putString("recall_suffix", suffix).putBoolean("recall_notice_explained", true).apply();
                recallMessage(message, recallNotice(suffix));
            })
            .setNegativeButton("取消", null)
            .show();
    }

    private String recallNotice(String suffix) {
        return AppAccess.from(this).session().account() + "撤回了一条消息" + (suffix == null ? "" : suffix.trim());
    }

    private void recallMessage(JsonObject message, String noticeText) {
        if (recallRequest != null) return;
        long messageId = Jsons.longValue(message, "id");
        if (messageId <= 0) return;
        binding.progress.setVisibility(View.VISIBLE);
        JsonObject body = new JsonObject();
        body.addProperty("notice_text", noticeText == null ? "" : noticeText.trim());
        if (MODE_CONVERSATION.equals(mode())) {
            recallRequest = AppAccess.from(this).repository().post("/api/user/messages/" + messageId + "/recall", body,
                result -> recallFinished(message, result));
            return;
        }
        long roomId = getIntent().getLongExtra(EXTRA_TARGET_ID, 0);
        String path = AppAccess.from(this).session().role() == Role.ADMIN
            ? "/api/admin/apps/" + AppAccess.from(this).session().selectedAppId() + "/chat-rooms/" + roomId + "/messages/" + messageId
            : "/api/user/chat-rooms/" + roomId + "/messages/" + messageId;
        recallRequest = AppAccess.from(this).repository().delete(path, body, result -> recallFinished(message, result));
    }

    private void recallFinished(JsonObject message, xyz.jjmxg.yiyunying.data.api.ApiResult result) {
        recallRequest = null;
        if (binding == null) return;
        binding.progress.setVisibility(View.INVISIBLE);
        if (result.isAuthenticationFailure()) { login(); return; }
        if (!result.isSuccessful()) {
            Snackbar.make(binding.getRoot(), result.message().isEmpty() ? "撤回失败" : result.message(), Snackbar.LENGTH_LONG).show();
            return;
        }
        invalidateMessageRequest();
        long messageId = Jsons.longValue(message, "id");
        messages.remove(messageId);
        messages.clear();
        adapter.submit(new ArrayList<>());
        lastId = 0;
        incrementalPollCount = 0;
        pendingNewMessageCount = 0;
        firstLoad = true;
        String editable = Jsons.string(result.dataObject(), "editable_content");
        if (editable.isEmpty()) editable = Jsons.string(message, "content");
        if (!editable.isEmpty()) {
            binding.messageInput.setText(editable);
            binding.messageInput.setSelection(binding.messageInput.length());
            binding.messageInput.requestFocus();
        }
        exitSelection();
        Snackbar.make(binding.getRoot(), result.message().isEmpty() ? "消息已撤回" : result.message(), Snackbar.LENGTH_SHORT).show();
        loadMessages();
    }

    private final class InlineAlbumAdapter extends RecyclerView.Adapter<InlineAlbumAdapter.Holder> {
        @NonNull @Override public Holder onCreateViewHolder(@NonNull ViewGroup parent, int viewType) {
            MaterialCardView card = new MaterialCardView(parent.getContext());
            RecyclerView.LayoutParams cardParams = new RecyclerView.LayoutParams(dp(128), dp(132));
            cardParams.setMargins(dp(3), 0, dp(3), 0);
            card.setLayoutParams(cardParams);
            card.setRadius(dp(6));
            card.setCardElevation(0);
            card.setStrokeWidth(0);

            FrameLayout frame = new FrameLayout(parent.getContext());
            ImageView image = new ImageView(parent.getContext());
            image.setScaleType(ImageView.ScaleType.CENTER_CROP);
            image.setLayoutParams(new FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT));
            View disabled = new View(parent.getContext());
            disabled.setBackgroundColor(0x9909090B);
            disabled.setLayoutParams(new FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT));

            TextView check = new TextView(parent.getContext());
            check.setGravity(Gravity.CENTER);
            check.setTextColor(Color.WHITE);
            check.setTextSize(18);
            FrameLayout.LayoutParams checkParams = new FrameLayout.LayoutParams(dp(34), dp(34), Gravity.TOP | Gravity.END);
            checkParams.setMargins(0, dp(4), dp(4), 0);

            TextView type = new TextView(parent.getContext());
            type.setGravity(Gravity.CENTER);
            type.setTextColor(Color.WHITE);
            type.setTextSize(11);
            type.setPadding(dp(7), 0, dp(7), 0);
            type.setBackgroundColor(0x99000000);
            FrameLayout.LayoutParams typeParams = new FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.WRAP_CONTENT, dp(25), Gravity.BOTTOM | Gravity.START);

            TextView size = new TextView(parent.getContext());
            size.setGravity(Gravity.CENTER);
            size.setTextColor(Color.WHITE);
            size.setTextSize(10);
            size.setPadding(dp(7), 0, dp(7), 0);
            size.setBackgroundColor(0x99000000);
            FrameLayout.LayoutParams sizeParams = new FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.WRAP_CONTENT, dp(25), Gravity.BOTTOM | Gravity.END);

            frame.addView(image);
            frame.addView(disabled);
            frame.addView(type, typeParams);
            frame.addView(size, sizeParams);
            frame.addView(check, checkParams);
            card.addView(frame);
            return new Holder(card, image, disabled, check, type, size);
        }

        @Override public void onBindViewHolder(@NonNull Holder holder, int position) {
            JsonObject item = inlineAlbumMedia.get(position);
            String uri = Jsons.string(item, "url");
            String mediaType = Jsons.string(item, "media_type");
            boolean video = "video".equals(mediaType);
            boolean allowed = UploadPolicyStore.accepts(ChatActivity.this, mediaType, Jsons.longValue(item, "size_bytes"));
            boolean selected = inlineAlbumSelected.contains(uri);

            Glide.with(holder.image)
                .load(Uri.parse(uri))
                .thumbnail(0.2f)
                .override(dp(128), dp(132))
                .dontAnimate()
                .centerCrop()
                .placeholder(R.drawable.ic_album)
                .error(R.drawable.ic_file)
                .into(holder.image);
            holder.disabled.setVisibility(allowed ? View.GONE : View.VISIBLE);
            holder.card.setStrokeWidth(selected ? dp(3) : 0);
            holder.card.setStrokeColor(ThemeColors.primary(ChatActivity.this));
            holder.check.setText(allowed ? (selected ? "✓" : "○") : "×");
            holder.check.setBackground(inlineAlbumBadge(selected
                ? ThemeColors.primary(ChatActivity.this) : 0x77000000));
            boolean gif = MediaKindDetector.isGif(Jsons.string(item, "mime_type"), Jsons.string(item, "file_name"));
            holder.type.setText(video ? mediaDurationLabel(Jsons.longValue(item, "duration_ms")) : (gif ? "GIF" : "图片"));
            holder.size.setText(mediaSizeText(Jsons.longValue(item, "size_bytes")));
            holder.itemView.setContentDescription((video ? "视频" : (gif ? "GIF 动图" : "图片"))
                + (selected ? "，已选择" : "，未选择"));
            holder.itemView.setOnClickListener(view -> {
                int current = holder.getBindingAdapterPosition();
                if (current >= 0 && current < getItemCount()) toggleInlineAlbumSelection(current);
            });
            holder.itemView.setOnLongClickListener(view -> {
                int current = holder.getBindingAdapterPosition();
                if (current >= 0 && current < getItemCount()) previewInlineAlbum(current);
                return true;
            });
        }

        @Override public void onViewRecycled(@NonNull Holder holder) {
            Glide.with(holder.image).clear(holder.image);
            super.onViewRecycled(holder);
        }

        @Override public int getItemCount() {
            return Math.min(inlineAlbumDisplayCount, inlineAlbumMedia.size());
        }

        final class Holder extends RecyclerView.ViewHolder {
            final MaterialCardView card;
            final ImageView image;
            final View disabled;
            final TextView check;
            final TextView type;
            final TextView size;

            Holder(MaterialCardView card, ImageView image, View disabled, TextView check, TextView type, TextView size) {
                super(card);
                this.card = card;
                this.image = image;
                this.disabled = disabled;
                this.check = check;
                this.type = type;
                this.size = size;
            }
        }
    }

    private final class ForwardTargetAdapter extends RecyclerView.Adapter<ForwardTargetAdapter.Holder> {
        private final String targetType;
        private final List<JsonObject> source;
        private final List<JsonObject> visible = new ArrayList<>();
        private final Set<Long> selected;
        private Runnable selectionChanged = () -> { };

        ForwardTargetAdapter(String targetType, List<JsonObject> source, Set<Long> selected) {
            this.targetType = targetType;
            this.source = source;
            this.selected = selected;
            setHasStableIds(true);
            filter("");
        }

        void setSelectionChangedListener(Runnable listener) {
            selectionChanged = listener == null ? () -> { } : listener;
        }

        void filter(String query) {
            String needle = query == null ? "" : query.trim().toLowerCase(java.util.Locale.CHINA);
            visible.clear();
            for (JsonObject target : source) {
                if (targetId(target, targetType) <= 0) continue;
                String label = forwardTargetLabel(target, targetType);
                if (needle.isEmpty() || label.toLowerCase(java.util.Locale.CHINA).contains(needle)) {
                    visible.add(target);
                }
            }
            notifyDataSetChanged();
        }

        void toggle(long id) {
            toggleForwardTarget(selected, id, !selected.contains(id), targetType);
            notifyDataSetChanged();
            selectionChanged.run();
        }

        @Override public long getItemId(int position) {
            return targetId(visible.get(position), targetType);
        }

        @NonNull @Override public Holder onCreateViewHolder(@NonNull ViewGroup parent, int viewType) {
            LinearLayout row = new LinearLayout(parent.getContext());
            row.setOrientation(LinearLayout.HORIZONTAL);
            row.setGravity(Gravity.CENTER_VERTICAL);
            row.setPadding(dp(4), dp(6), dp(4), dp(6));
            row.setLayoutParams(new RecyclerView.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, dp(64)));

            MaterialCardView avatarCard = new MaterialCardView(parent.getContext());
            avatarCard.setRadius(dp(22));
            avatarCard.setCardElevation(0);
            avatarCard.setCardBackgroundColor(getColor(R.color.surface_container_high));
            ImageView avatar = new ImageView(parent.getContext());
            avatar.setScaleType(ImageView.ScaleType.CENTER_CROP);
            avatarCard.addView(avatar, new FrameLayout.LayoutParams(dp(44), dp(44)));
            LinearLayout.LayoutParams avatarParams = new LinearLayout.LayoutParams(dp(44), dp(44));
            avatarParams.setMarginEnd(dp(8));
            row.addView(avatarCard, avatarParams);

            MaterialCheckBox check = new MaterialCheckBox(parent.getContext());
            check.setGravity(Gravity.CENTER_VERTICAL);
            check.setTextColor(getColor(R.color.on_surface));
            check.setTextSize(15f);
            check.setMaxLines(2);
            check.setEllipsize(android.text.TextUtils.TruncateAt.END);
            row.addView(check, new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.MATCH_PARENT, 1f));
            Holder holder = new Holder(row, avatar, check);
            row.setOnClickListener(view -> {
                int position = holder.getBindingAdapterPosition();
                if (position != RecyclerView.NO_POSITION && position >= 0 && position < getItemCount()) {
                    toggle(getItemId(position));
                }
            });
            return holder;
        }

        @Override public void onBindViewHolder(@NonNull Holder holder, int position) {
            JsonObject target = visible.get(position);
            long id = targetId(target, targetType);
            holder.check.setOnCheckedChangeListener(null);
            holder.check.setText(forwardTargetLabel(target, targetType));
            holder.check.setChecked(selected.contains(id));
            holder.check.setOnCheckedChangeListener((button, enabled) -> {
                if (enabled == selected.contains(id)) return;
                toggle(id);
            });
            String avatar = Jsons.string(target, "avatar");
            if (avatar.isEmpty()) avatar = Jsons.string(target, "icon");
            if (avatar.isEmpty()) avatar = Jsons.string(target, "logo");
            int placeholder = "private".equals(targetType) ? R.drawable.ic_person
                : ("forum_post".equals(targetType) ? R.drawable.ic_forum : R.drawable.ic_group);
            ImageLoader.get().load(ImageLoader.get().absoluteUrl(ChatActivity.this, avatar), holder.avatar, placeholder);
        }

        @Override public int getItemCount() {
            return visible.size();
        }

        final class Holder extends RecyclerView.ViewHolder {
            final ImageView avatar;
            final MaterialCheckBox check;

            Holder(View itemView, ImageView avatar, MaterialCheckBox check) {
                super(itemView);
                this.avatar = avatar;
                this.check = check;
            }
        }
    }
    private android.graphics.drawable.GradientDrawable inlineAlbumBadge(int color) {
        android.graphics.drawable.GradientDrawable drawable = new android.graphics.drawable.GradientDrawable();
        drawable.setShape(android.graphics.drawable.GradientDrawable.OVAL);
        drawable.setColor(color);
        drawable.setStroke(dp(1), 0xCCFFFFFF);
        return drawable;
    }

    private String mediaDurationLabel(long durationMs) {
        long seconds = Math.max(0L, durationMs / 1000L);
        return String.format(java.util.Locale.CHINA, "▶ %d:%02d", seconds / 60L, seconds % 60L);
    }

    private int dp(int value) {
        return Math.round(value * getResources().getDisplayMetrics().density);
    }

    private String readPath() {
        long id = getIntent().getLongExtra(EXTRA_TARGET_ID, 0);
        long appId = AppAccess.from(this).session().selectedAppId();
        switch (mode()) {
            case MODE_ROOM:
                return AppAccess.from(this).session().role() == Role.ADMIN
                    ? "/api/admin/apps/" + appId + "/chat-rooms/" + id + "/messages"
                    : "/api/user/chat-rooms/" + id + "/messages";
            case MODE_CONVERSATION: return "/api/user/conversations/" + id + "/messages";
            case MODE_SERVICE_ADMIN: return "/api/admin/apps/" + appId + "/service-sessions/" + id + "/messages";
            default: return "/api/user/service/messages";
        }
    }

    private String writePath() {
        long id = getIntent().getLongExtra(EXTRA_TARGET_ID, 0);
        long appId = AppAccess.from(this).session().selectedAppId();
        switch (mode()) {
            case MODE_ROOM:
                return AppAccess.from(this).session().role() == Role.ADMIN
                    ? "/api/admin/apps/" + appId + "/chat-rooms/" + id + "/messages"
                    : "/api/user/chat-rooms/" + id + "/messages";
            case MODE_CONVERSATION: return "/api/user/messages/private";
            case MODE_SERVICE_ADMIN: return "/api/admin/apps/" + appId + "/service-sessions/" + id + "/reply";
            default: return "/api/user/service/messages";
        }
    }

    private String mode() {
        String value = getIntent().getStringExtra(EXTRA_MODE);
        return value == null ? MODE_SERVICE_USER : value;
    }

    private void showGroupTools() {
        long roomId = getIntent().getLongExtra(EXTRA_TARGET_ID, 0L);
        if (roomId <= 0L) {
            Snackbar.make(binding.getRoot(), "无法识别当前" + roomEntityLabel(), Snackbar.LENGTH_LONG).show();
            return;
        }
        String prefix = isChatRoom() ? "聊天室" : "群";
        String[] items = {memberEntityLabel() + "与权限", prefix + "文件", prefix + "相册", prefix + "投票", prefix + "接龙"};
        new YiyunyingDialogBuilder(this)
            .setTitle(roomEntityLabel() + "工具")
            .setItems(items, (dialog, which) -> {
                switch (which) {
                    case 1: GroupSpaceActivity.open(this, roomId, normalTitle, "files"); break;
                    case 2: GroupSpaceActivity.open(this, roomId, normalTitle, "albums"); break;
                    case 3: GroupSpaceActivity.open(this, roomId, normalTitle, "votes"); break;
                    case 4: GroupSpaceActivity.open(this, roomId, normalTitle, "solitaires"); break;
                    default: GroupSpaceActivity.open(this, roomId, normalTitle, "members"); break;
                }
            })
            .setNegativeButton("关闭", null)
            .show();
    }

    private void loadGroupTool(String title, String endpoint) {
        binding.progress.setVisibility(View.VISIBLE);
        groupToolRequest = AppAccess.from(this).repository().get(roomToolPath(endpoint), new LinkedHashMap<>(), result -> {
            groupToolRequest = null;
            if (binding == null) return;
            binding.progress.setVisibility(View.INVISIBLE);
            if (result.isAuthenticationFailure()) { login(); return; }
            if (!result.isSuccessful()) {
                Snackbar.make(binding.getRoot(), result.message().isEmpty() ? "加载失败" : result.message(), Snackbar.LENGTH_LONG).show();
                return;
            }
            RecordDetailDialog.show(this, title, result.dataObject());
        });
    }

    private void groupToolForm(ActionSpec action) {
        DynamicFormDialog.show(this, action, null, body -> {
            postGroupTool(action.pathTemplate(), body, action.idempotent());
        });
    }

    private void groupToolNestedForm(ActionSpec action, String idField, String endpointTemplate) {
        DynamicFormDialog.show(this, action, null, body -> {
            long id = Jsons.longValue(body, idField);
            if (id <= 0) {
                Snackbar.make(binding.getRoot(), "ID 必须大于 0", Snackbar.LENGTH_LONG).show();
                return;
            }
            postGroupTool(roomToolPath(endpointTemplate.replace("{id}", String.valueOf(id))), body, action.idempotent());
        });
    }

    private void postGroupTool(String path, JsonObject body, boolean idempotent) {
        binding.progress.setVisibility(View.VISIBLE);
        groupToolRequest = AppAccess.from(this).repository().post(path, body,
            idempotent ? UUID.randomUUID().toString() : "", result -> {
                groupToolRequest = null;
                if (binding == null) return;
                binding.progress.setVisibility(View.INVISIBLE);
                if (result.isAuthenticationFailure()) { login(); return; }
                Snackbar.make(binding.getRoot(), result.isSuccessful()
                    ? (result.message().isEmpty() ? "操作成功" : result.message())
                    : (result.message().isEmpty() ? "操作失败" : result.message()), Snackbar.LENGTH_LONG).show();
            });
    }

    private String roomToolPath(String endpoint) {
        return "/api/user/chat-rooms/" + getIntent().getLongExtra(EXTRA_TARGET_ID, 0) + "/" + endpoint;
    }
    private void login() {
        AppAccess.from(this).session().clearAuthentication();
        startActivity(new Intent(this, LoginActivity.class).putExtra(LoginActivity.EXTRA_FORCE_LOGIN, true)
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TASK));
        finish();
    }

    @Override protected void onDestroy() {
        unregisterRecentPhotoObserver();
        handler.removeCallbacks(recentPhotoReload);
        handler.removeCallbacks(poll);
        if (pollRequest != null) pollRequest.cancel();
        if (cachedMessageRequest != null) cachedMessageRequest.cancel();
        if (cachedConversationResolveRequest != null) cachedConversationResolveRequest.cancel();
        if (conversationResolveRequest != null) conversationResolveRequest.cancel();
        if (sendRequest != null) sendRequest.cancel();
        if (policyRequest != null) policyRequest.cancel();
        if (groupToolRequest != null) groupToolRequest.cancel();
        if (roomMetadataRequest != null) roomMetadataRequest.cancel();
        roomMetadataGeneration++;
        if (readRequest != null) readRequest.cancel();
        if (uploadRequest != null) uploadRequest.cancel();
        for (RequestHandle request : batchUploadRequests) request.cancel();
        batchUploadRequests.clear();
        activeUploadBatch = null;
        if (recallRequest != null) recallRequest.cancel();
        if (stickerRequest != null) stickerRequest.cancel();
        if (forwardRequest != null) forwardRequest.cancel();
        if (messageActionRequest != null) messageActionRequest.cancel();
        if (searchHistoryRequest != null) searchHistoryRequest.cancel();
        if (callMemberRequest != null) callMemberRequest.cancel();
        if (mentionRequest != null) mentionRequest.cancel();
        handler.removeCallbacks(voiceRecordingTicker);
        handler.removeCallbacks(serverSpeechTicker);
        handler.removeCallbacks(speechSilenceTimeout);
        if (binding != null) binding.inlineAlbumList.removeCallbacks(inlineAlbumEdgeScroller);
        stopSpeechIconAnimation();
        releaseVoiceRecorder();
        releaseServerSpeechRecorder();
        if (speechEngine != null) {
            speechEngine.cancel();
            speechEngine.destroy();
            speechEngine = null;
        }
        if (offlineSpeech != null) {
            offlineSpeech.shutdown();
            offlineSpeech = null;
        }
        // Do not obtain a Glide RequestManager while the Activity is being destroyed.
        // Some Android 16 builds already report this Activity as destroyed at this point.
        if (binding != null) {
            binding.chatBackground.setImageDrawable(null);
            binding.chatBackground.setVisibility(View.GONE);
        }
        mediaExecutor.shutdownNow();
        binding = null;
        super.onDestroy();
    }
}
