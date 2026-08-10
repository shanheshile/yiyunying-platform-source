package xyz.jjmxg.yiyunying.ui.moment;

import android.Manifest;
import android.content.Context;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.database.Cursor;
import android.graphics.Rect;
import android.location.Address;
import android.location.Geocoder;
import android.location.Location;
import android.location.LocationManager;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.CancellationSignal;
import android.os.Handler;
import android.os.Looper;
import android.provider.OpenableColumns;
import android.text.Editable;
import android.text.TextUtils;
import android.text.TextWatcher;
import android.view.Gravity;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.view.ViewTreeObserver;
import android.view.Window;
import android.view.WindowManager;
import android.view.inputmethod.EditorInfo;
import android.view.inputmethod.InputMethodManager;
import android.widget.GridLayout;
import android.widget.LinearLayout;
import android.widget.ImageView;
import android.widget.ScrollView;
import android.widget.TextView;

import androidx.activity.result.ActivityResultLauncher;
import androidx.activity.result.contract.ActivityResultContracts;
import androidx.annotation.NonNull;
import androidx.core.content.ContextCompat;
import androidx.recyclerview.widget.DiffUtil;
import androidx.recyclerview.widget.GridLayoutManager;
import androidx.recyclerview.widget.LinearLayoutManager;
import androidx.recyclerview.widget.RecyclerView;

import com.google.android.material.bottomsheet.BottomSheetDialog;
import com.google.android.material.card.MaterialCardView;
import com.google.android.material.imageview.ShapeableImageView;
import com.google.android.material.shape.ShapeAppearanceModel;
import com.google.android.material.snackbar.Snackbar;
import com.google.gson.JsonArray;
import com.google.gson.JsonElement;
import com.google.gson.JsonNull;
import com.google.gson.JsonObject;
import com.google.gson.JsonParser;

import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Locale;
import java.util.Map;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.core.AppAccess;
import xyz.jjmxg.yiyunying.core.RuntimeLanguage;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.data.api.RequestHandle;
import xyz.jjmxg.yiyunying.databinding.ActivityMomentTimelineBinding;
import xyz.jjmxg.yiyunying.databinding.ItemMomentCommentBinding;
import xyz.jjmxg.yiyunying.databinding.ItemMomentMediaBinding;
import xyz.jjmxg.yiyunying.databinding.ItemMomentTimelineBinding;
import xyz.jjmxg.yiyunying.databinding.SheetMomentCommentsBinding;
import xyz.jjmxg.yiyunying.databinding.SheetMomentComposerBinding;
import xyz.jjmxg.yiyunying.databinding.SheetMomentLikesBinding;
import xyz.jjmxg.yiyunying.ui.auth.LoginActivity;
import xyz.jjmxg.yiyunying.ui.common.CommentVoiceRecorder;
import xyz.jjmxg.yiyunying.ui.common.GlassBottomSheet;
import xyz.jjmxg.yiyunying.ui.common.ImageLoader;
import xyz.jjmxg.yiyunying.ui.common.MediaViewRenderer;
import xyz.jjmxg.yiyunying.ui.common.SystemInsetActivity;
import xyz.jjmxg.yiyunying.ui.common.YiyunyingDialogBuilder;
import xyz.jjmxg.yiyunying.ui.location.LocationPickerActivity;
import xyz.jjmxg.yiyunying.ui.profile.UserProfileActivity;
import xyz.jjmxg.yiyunying.ui.social.FriendPickerActivity;
import xyz.jjmxg.yiyunying.ui.social.SocialDirectoryActivity;
import xyz.jjmxg.yiyunying.ui.upload.ContentUriRequestBody;
import xyz.jjmxg.yiyunying.ui.upload.ImageGalleryActivity;
import xyz.jjmxg.yiyunying.ui.upload.MediaPickerActivity;

/** A real, media-backed Moments timeline. Server-side rules remain authoritative. */
public final class MomentTimelineActivity extends SystemInsetActivity {
    private static final String EXTRA_COMPOSE = "compose";
    private static final String EXTRA_USER_ID = "user_id";
    private static final String EXTRA_USER_TITLE = "user_title";
    private static final String EXTRA_MOMENT_ID = "moment_id";
    private static final String EXTRA_OPEN_COMMENTS = "open_comments";
    private static final String EXTRA_COMMENT_ID = "comment_id";
    private static final String EXTRA_PROFILE_TIMELINE = "profile_timeline";
    private static final String EXTRA_MINE = "mine";
    private static final int MAX_MEDIA = 9;
    private static final String[] VISIBILITY_VALUES = {
        "inherit", "public", "friends", "followers", "selected", "exclude", "private"
    };
    private static final String[] VISIBILITY_LABELS = {
        "跟随全局", "所有人", "好友", "关注我的人", "仅指定用户", "除指定用户外", "仅自己"
    };
    private static final Integer[] VISIBLE_DAY_VALUES = {null, 0, 3, 30, 180, 365};
    private static final String[] VISIBLE_DAY_LABELS = {"跟随全局", "永久", "3 天", "1 个月", "半年", "1 年"};

    private final Handler searchHandler = new Handler(Looper.getMainLooper());
    private final ArrayList<Uri> composerUris = new ArrayList<>();
    private final ArrayList<Long> composerVisibilityUserIds = new ArrayList<>();
    private final Runnable delayedSearch = this::load;
    private ActivityMomentTimelineBinding binding;
    private MomentAdapter adapter;
    private long listGeneration;
    private RequestHandle listRequest;
    private RequestHandle actionRequest;
    private RequestHandle uploadRequest;
    private RequestHandle commentRequest;
    private RequestHandle commentLikeRequest;
    private RequestHandle commentStickerRequest;
    private RequestHandle commentVoiceUploadRequest;
    private RequestHandle likesRequest;
    private BottomSheetDialog composerDialog;
    private SheetMomentComposerBinding composerBinding;
    private BottomSheetDialog commentsDialog;
    private BottomSheetDialog commentStickerDialog;
    private SheetMomentCommentsBinding commentsBinding;
    private BottomSheetDialog likesDialog;
    private SheetMomentLikesBinding likesBinding;
    private JsonObject editingMoment;
    private boolean visibilityOnlyEdit;
    private JsonObject commentsMoment;
    private JsonObject likesMoment;
    private JsonObject pendingForwardMoment;
    private long replyingCommentId;
    private final java.util.Set<Long> expandedMomentCommentThreads =
        new java.util.LinkedHashSet<>();
    private ViewTreeObserver.OnGlobalLayoutListener commentsLayoutListener;
    private int commentsViewportHeight = -1;
    private boolean commentsViewportResizePosted;
    private long targetCommentId;
    private boolean openCommentsAfterLoad;
    private boolean commentsOpenedFromIntent;
    private long targetUserId;
    private long targetMomentId;
    private boolean profileTimeline;
    private boolean ownProfileTimeline;
    private String targetUserTitle = "";
    private String composerVisibilityMode = "inherit";
    private Integer composerVisibleDays;
    private CommentVoiceRecorder commentVoiceRecorder;
    private CommentVoiceRecorder.Result pendingCommentVoice;
    private String locationName = "";
    private String locationAddress = "";
    private Double latitude;
    private Double longitude;

    private final ActivityResultLauncher<Intent> mediaPicker = registerForActivityResult(
        new ActivityResultContracts.StartActivityForResult(), result -> {
            if (!isUiActive() || result.getResultCode() != RESULT_OK
                || result.getData() == null || composerBinding == null) return;
            ArrayList<Uri> selected = result.getData().getParcelableArrayListExtra(MediaPickerActivity.EXTRA_SELECTED_URIS);
            if (selected == null) return;
            // Returning from the full picker synchronizes selection even when the user used Back.
            composerUris.clear();
            for (Uri uri : selected) {
                if (uri != null && composerUris.size() < MAX_MEDIA) composerUris.add(uri);
            }
            renderComposerMedia();
        });

    private final ActivityResultLauncher<Intent> visibilityFriendPicker = registerForActivityResult(
        new ActivityResultContracts.StartActivityForResult(), result -> {
            if (!isUiActive() || result.getResultCode() != RESULT_OK
                || result.getData() == null || composerBinding == null) return;
            long[] selected = result.getData().getLongArrayExtra(FriendPickerActivity.EXTRA_SELECTED_IDS);
            composerVisibilityUserIds.clear();
            if (selected != null) {
                for (long userId : selected) if (userId > 0L) composerVisibilityUserIds.add(userId);
            }
            renderVisibility();
        });

    private final ActivityResultLauncher<Intent> forwardFriendPicker = registerForActivityResult(
        new ActivityResultContracts.StartActivityForResult(), result -> {
            JsonObject moment = pendingForwardMoment;
            pendingForwardMoment = null;
            if (!isUiActive() || result.getResultCode() != RESULT_OK
                || result.getData() == null || moment == null) return;
            JsonObject selected = firstSelected(result.getData());
            long userId = Jsons.longValue(selected, "user_id");
            if (userId <= 0) { message("没有取得有效的好友编号"); return; }
            sendMomentForward(moment, "private", userId, false);
        });

    private final ActivityResultLauncher<Intent> forwardGroupPicker = registerForActivityResult(
        new ActivityResultContracts.StartActivityForResult(), result -> {
            JsonObject moment = pendingForwardMoment;
            pendingForwardMoment = null;
            if (!isUiActive() || result.getResultCode() != RESULT_OK
                || result.getData() == null || moment == null) return;
            JsonObject selected = firstSelected(result.getData());
            long roomId = Jsons.longValue(selected, "id");
            if (roomId <= 0) { message("没有取得有效的群聊编号"); return; }
            sendMomentForward(moment, "group", roomId, false);
        });

    private final ActivityResultLauncher<Intent> forwardChatroomPicker = registerForActivityResult(
        new ActivityResultContracts.StartActivityForResult(), result -> {
            JsonObject moment = pendingForwardMoment;
            pendingForwardMoment = null;
            if (!isUiActive() || result.getResultCode() != RESULT_OK
                || result.getData() == null || moment == null) return;
            JsonObject selected = firstSelected(result.getData());
            long roomId = Jsons.longValue(selected, "id");
            if (roomId <= 0) { message("没有取得有效的聊天室编号"); return; }
            sendMomentForward(moment, "chat_room", roomId, false);
        });

    private final ActivityResultLauncher<String[]> locationPermission = registerForActivityResult(
        new ActivityResultContracts.RequestMultiplePermissions(), result -> {
            if (!isUiActive()) return;
            boolean granted = Boolean.TRUE.equals(result.get(Manifest.permission.ACCESS_FINE_LOCATION))
                || Boolean.TRUE.equals(result.get(Manifest.permission.ACCESS_COARSE_LOCATION));
            if (granted) locate();
            else message("需要定位权限才能添加附近位置");
        });

    private final ActivityResultLauncher<String> commentVoicePermission = registerForActivityResult(
        new ActivityResultContracts.RequestPermission(), granted -> {
            if (!isUiActive() || commentsBinding == null) return;
            if (Boolean.TRUE.equals(granted)) startCommentVoiceRecording();
            else message("需要麦克风权限才能录制语音评论");
        });

    private final ActivityResultLauncher<Intent> locationPicker = registerForActivityResult(
        new ActivityResultContracts.StartActivityForResult(), result -> {
            if (!isUiActive() || composerBinding == null
                || result.getResultCode() != RESULT_OK || result.getData() == null) return;
            Intent data = result.getData();
            String selectedName = data.getStringExtra(LocationPickerActivity.EXTRA_LOCATION_NAME);
            String selectedAddress = data.getStringExtra(LocationPickerActivity.EXTRA_ADDRESS);
            double selectedLatitude = data.getDoubleExtra(LocationPickerActivity.EXTRA_LATITUDE, Double.NaN);
            double selectedLongitude = data.getDoubleExtra(LocationPickerActivity.EXTRA_LONGITUDE, Double.NaN);
            if (selectedName == null || selectedName.trim().isEmpty()
                || Double.isNaN(selectedLatitude) || Double.isNaN(selectedLongitude)
                || selectedLatitude < -90d || selectedLatitude > 90d
                || selectedLongitude < -180d || selectedLongitude > 180d) {
                message("没有取得有效的位置");
                return;
            }
            locationName = selectedName.trim();
            locationAddress = selectedAddress == null ? "" : selectedAddress.trim();
            latitude = selectedLatitude;
            longitude = selectedLongitude;
            renderLocation();
        });

    public static void open(Context context, boolean compose) {
        context.startActivity(new Intent(context, MomentTimelineActivity.class).putExtra(EXTRA_COMPOSE, compose));
    }

    public static void openForUser(Context context, long userId, String title) {
        if (userId <= 0) return;
        long actorId = AppAccess.from(context).session().actorId();
        context.startActivity(new Intent(context, MomentTimelineActivity.class)
            .putExtra(EXTRA_USER_ID, userId)
            .putExtra(EXTRA_PROFILE_TIMELINE, true)
            .putExtra(EXTRA_MINE, userId == actorId)
            .putExtra(EXTRA_USER_TITLE, title == null ? "" : title));
    }

    public static void openMine(Context context) {
        context.startActivity(new Intent(context, MomentTimelineActivity.class)
            .putExtra(EXTRA_PROFILE_TIMELINE, true)
            .putExtra(EXTRA_MINE, true));
    }

    public static void openMoment(Context context, long momentId, long userId, String title) {
        if (momentId <= 0) return;
        context.startActivity(new Intent(context, MomentTimelineActivity.class)
            .putExtra(EXTRA_MOMENT_ID, momentId)
            .putExtra(EXTRA_USER_ID, Math.max(0L, userId))
            .putExtra(EXTRA_USER_TITLE, title == null ? "" : title));
    }

    public static void openMomentComments(Context context, long momentId, long userId,
                                          String title, long commentId) {
        if (momentId <= 0) return;
        context.startActivity(new Intent(context, MomentTimelineActivity.class)
            .putExtra(EXTRA_MOMENT_ID, momentId)
            .putExtra(EXTRA_USER_ID, Math.max(0L, userId))
            .putExtra(EXTRA_USER_TITLE, title == null ? "" : title)
            .putExtra(EXTRA_OPEN_COMMENTS, true)
            .putExtra(EXTRA_COMMENT_ID, Math.max(0L, commentId)));
    }

    private boolean isUiActive() {
        return binding != null && !isFinishing() && !isDestroyed();
    }

    @Override protected void onCreate(Bundle state) {
        super.onCreate(state);
        targetUserId = getIntent().getLongExtra(EXTRA_USER_ID, 0L);
        targetMomentId = getIntent().getLongExtra(EXTRA_MOMENT_ID, 0L);
        targetCommentId = getIntent().getLongExtra(EXTRA_COMMENT_ID, 0L);
        openCommentsAfterLoad = getIntent().getBooleanExtra(EXTRA_OPEN_COMMENTS, false);
        profileTimeline = targetMomentId <= 0 && (getIntent().getBooleanExtra(EXTRA_PROFILE_TIMELINE, false)
            || targetUserId > 0L);
        long actorId = AppAccess.from(this).session().actorId();
        ownProfileTimeline = MomentDisplayPolicy.usesMineQuery(
            profileTimeline,
            getIntent().getBooleanExtra(EXTRA_MINE, false),
            targetUserId,
            actorId);
        if (ownProfileTimeline) targetUserId = actorId;
        targetUserTitle = getIntent().getStringExtra(EXTRA_USER_TITLE);
        if (targetUserTitle == null) targetUserTitle = "";
        binding = ActivityMomentTimelineBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());
        RuntimeLanguage.setDynamicToolbarTitle(binding.toolbar, targetMomentId > 0
            ? "动态详情"
            : targetUserId > 0 && !targetUserTitle.isEmpty() ? targetUserTitle + "的动态" : "动态");
        binding.toolbar.setNavigationOnClickListener(view -> finish());
        adapter = new MomentAdapter();
        binding.recycler.setLayoutManager(new LinearLayoutManager(this));
        binding.recycler.setHasFixedSize(false);
        binding.recycler.setItemViewCacheSize(8);
        binding.recycler.setItemAnimator(null);
        binding.recycler.setAdapter(adapter);
        xyz.jjmxg.yiyunying.ui.common.TopCenterDoubleTap.attach(
            binding.toolbar, binding.recycler);
        binding.swipeRefresh.setOnRefreshListener(this::load);
        binding.createButton.setOnClickListener(view -> showComposer(null));
        boolean ownTimeline = ownProfileTimeline || targetUserId <= 0 || targetUserId == actorId;
        binding.createButton.setVisibility(targetMomentId <= 0 && ownTimeline ? View.VISIBLE : View.GONE);
        binding.emptyText.setText(ownTimeline ? "还没有发布动态" : "暂无你可以查看的动态");
        binding.searchLayout.setVisibility(targetMomentId > 0 ? View.GONE : View.VISIBLE);
        if (targetMomentId <= 0) {
            binding.searchInput.addTextChangedListener(new TextWatcher() {
                @Override public void beforeTextChanged(CharSequence value, int start, int count, int after) { }
                @Override public void onTextChanged(CharSequence value, int start, int before, int count) {
                    searchHandler.removeCallbacks(delayedSearch);
                    searchHandler.postDelayed(delayedSearch, 320L);
                }
                @Override public void afterTextChanged(Editable value) { }
            });
        }
        load();
        if (getIntent().getBooleanExtra(EXTRA_COMPOSE, false)) {
            binding.getRoot().post(() -> {
                if (isUiActive()) showComposer(null);
            });
        }
    }

    private void load() {
        load(true);
    }

    private void load(boolean allowCache) {
        if (!isUiActive()) return;
        long generation = ++listGeneration;
        if (listRequest != null) {
            listRequest.cancel();
            listRequest = null;
        }
        if (targetMomentId > 0) {
            loadMomentDetail(generation, allowCache);
            return;
        }
        Map<String, String> query = new LinkedHashMap<>();
        query.put("page", "1");
        query.put("limit", "50");
        if (ownProfileTimeline) query.put("mine", "1");
        else if (targetUserId > 0) query.put("user_id", String.valueOf(targetUserId));
        String keyword = text(binding.searchInput);
        if (!keyword.isEmpty()) query.put("keyword", keyword);
        binding.progress.setVisibility(View.VISIBLE);
        if (allowCache && adapter.isEmpty()) {
            AppAccess.from(this).repository().getCached("/api/user/moments", query, cached -> {
                if (!isUiActive() || generation != listGeneration || !cached.isSuccessful() || !adapter.isEmpty()) return;
                renderMoments(cached.objectItems(), generation);
                if (isUiActive() && generation == listGeneration) binding.progress.setVisibility(View.INVISIBLE);
            });
        }
        listRequest = AppAccess.from(this).repository().get("/api/user/moments", query, result -> {
            if (generation == listGeneration) listRequest = null;
            if (!isUiActive() || generation != listGeneration) return;
            binding.progress.setVisibility(View.INVISIBLE);
            binding.swipeRefresh.setRefreshing(false);
            if (result.isAuthenticationFailure()) { login(); return; }
            if (!result.isSuccessful()) { message(result.message().isEmpty() ? "动态加载失败" : result.message()); return; }
            renderMoments(result.objectItems(), generation);
        });
    }

    private void loadMomentDetail(long generation, boolean allowCache) {
        binding.progress.setVisibility(View.VISIBLE);
        String path = "/api/user/moments/" + targetMomentId;
        LinkedHashMap<String, String> query = new LinkedHashMap<>();
        if (allowCache && adapter.isEmpty()) {
            AppAccess.from(this).repository().getCached(path, query, cached -> {
                if (!isUiActive() || generation != listGeneration || !cached.isSuccessful() || !adapter.isEmpty()) return;
                JsonObject moment = Jsons.object(cached.dataObject(), "moment");
                List<JsonObject> cachedItems = new ArrayList<>();
                if (moment.size() > 0) cachedItems.add(moment);
                renderMoments(cachedItems, generation);
                if (isUiActive() && generation == listGeneration) binding.progress.setVisibility(View.INVISIBLE);
            });
        }
        listRequest = AppAccess.from(this).repository().get(
            path,
            query,
            result -> {
                if (generation == listGeneration) listRequest = null;
                if (!isUiActive() || generation != listGeneration) return;
                binding.progress.setVisibility(View.INVISIBLE);
                binding.swipeRefresh.setRefreshing(false);
                if (result.isAuthenticationFailure()) { login(); return; }
                if (!result.isSuccessful()) {
                    renderMoments(new ArrayList<>(), generation);
                    binding.emptyText.setText(result.message().isEmpty() ? "动态不存在或你无权查看" : result.message());
                    return;
                }
                JsonObject moment = Jsons.object(result.dataObject(), "moment");
                List<JsonObject> items = new ArrayList<>();
                if (moment.size() > 0) items.add(moment);
                renderMoments(items, generation);
            }
        );
    }

    private void renderMoments(List<JsonObject> values, long generation) {
        if (!isUiActive() || adapter == null || generation != listGeneration) return;
        ArrayList<JsonObject> snapshot = prepareMomentsForDisplay(values);
        Runnable render = () -> {
            if (!isUiActive() || adapter == null || generation != listGeneration) return;
            adapter.submit(snapshot);
            binding.emptyText.setVisibility(snapshot.isEmpty() ? View.VISIBLE : View.GONE);
            if (openCommentsAfterLoad && !commentsOpenedFromIntent && !snapshot.isEmpty()) {
                commentsOpenedFromIntent = true;
                binding.recycler.post(() -> {
                    if (isUiActive()) showComments(snapshot.get(0));
                });
            }
        };
        if (binding.recycler.isComputingLayout()) binding.recycler.post(render);
        else render.run();
    }

    private void showComposer(JsonObject item) {
        showComposer(item, false);
    }

    private void showVisibilityEditor(JsonObject item) {
        showComposer(item, true);
    }

    private void showComposer(JsonObject item, boolean visibilityOnly) {
        if (!isUiActive()) return;
        if (composerDialog != null && composerDialog.isShowing()) return;
        editingMoment = item == null ? null : item.deepCopy();
        visibilityOnlyEdit = visibilityOnly && editingMoment != null;
        composerUris.clear();
        composerVisibilityUserIds.clear();
        if (item != null) {
            for (JsonElement value : Jsons.array(item, "visibility_user_ids")) {
                try {
                    long userId = value.getAsLong();
                    if (userId > 0L) composerVisibilityUserIds.add(userId);
                } catch (RuntimeException ignored) { }
            }
        }
        composerVisibilityMode = item == null ? "inherit" : safeVisibilityMode(Jsons.string(item, "visibility_mode"));
        composerVisibleDays = item == null || !item.has("visible_days") || item.get("visible_days").isJsonNull()
            ? null : Jsons.intValue(item, "visible_days", 0);
        locationName = item == null ? "" : Jsons.string(item, "location_name");
        locationAddress = item == null ? "" : Jsons.string(item, "location_address");
        if (locationAddress.isEmpty()) locationAddress = locationName;
        latitude = nullableDouble(item, "latitude");
        longitude = nullableDouble(item, "longitude");
        BottomSheetDialog dialog = new BottomSheetDialog(this);
        SheetMomentComposerBinding sheetBinding = SheetMomentComposerBinding.inflate(getLayoutInflater());
        composerDialog = dialog;
        composerBinding = sheetBinding;
        dialog.setContentView(sheetBinding.getRoot());
        composerBinding.titleText.setText(visibilityOnlyEdit ? "编辑可见范围" : item == null ? "发布动态" : "编辑内容");
        composerBinding.publishButton.setText(visibilityOnlyEdit ? "保存范围" : item == null ? "发布" : "保存");
        if (item != null) composerBinding.contentInput.setText(Jsons.string(item, "content"));
        composerBinding.contentLayout.setVisibility(visibilityOnlyEdit ? View.GONE : View.VISIBLE);
        composerBinding.mediaHint.setVisibility(visibilityOnlyEdit ? View.GONE : View.VISIBLE);
        composerBinding.mediaGrid.setVisibility(visibilityOnlyEdit ? View.GONE : View.VISIBLE);
        composerBinding.mediaActionsRow.setVisibility(visibilityOnlyEdit ? View.GONE : View.VISIBLE);
        composerBinding.locationHint.setVisibility(visibilityOnlyEdit ? View.GONE : View.VISIBLE);
        composerBinding.mediaGrid.setLayoutManager(new GridLayoutManager(this, 3));
        composerBinding.mediaGrid.setItemAnimator(null);
        composerBinding.mediaButton.setOnClickListener(view -> mediaPicker.launch(
            MediaPickerActivity.intent(this, false, new ArrayList<>(composerUris))));
        composerBinding.locationButton.setOnClickListener(view -> locationPicker.launch(
            LocationPickerActivity.pickerIntent(
                this, locationName, locationAddress, latitude, longitude)));
        composerBinding.locationClearButton.setOnClickListener(view -> {
            locationName = "";
            locationAddress = "";
            latitude = null;
            longitude = null;
            renderLocation();
        });
        composerBinding.visibilityModeButton.setOnClickListener(view -> chooseVisibilityMode());
        composerBinding.visibleDaysButton.setOnClickListener(view -> chooseVisibleDays());
        composerBinding.visibilityUsersButton.setOnClickListener(view -> openVisibilityFriendPicker());
        composerBinding.cancelButton.setOnClickListener(view -> dialog.dismiss());
        composerBinding.publishButton.setOnClickListener(view -> submitMoment());
        renderComposerMedia();
        renderLocation();
        renderVisibility();
        dialog.setOnDismissListener(ignored -> {
            if (composerDialog != dialog) return;
            composerUris.clear();
            composerBinding = null;
            composerDialog = null;
            editingMoment = null;
            visibilityOnlyEdit = false;
            composerVisibilityUserIds.clear();
            locationName = "";
            locationAddress = "";
            latitude = null;
            longitude = null;
            composerVisibilityMode = "inherit";
            composerVisibleDays = null;
        });
        GlassBottomSheet.prepare(dialog, this, 0.92f, false);
        dialog.show();
    }

    private void renderComposerMedia() {
        if (composerBinding == null) return;
        JsonArray existing = editingMoment == null ? new JsonArray() : Jsons.array(editingMoment, "attachments");
        int count = composerUris.isEmpty() ? existing.size() : composerUris.size();
        composerBinding.mediaHint.setText("图片与视频 " + count + "/" + MAX_MEDIA
            + (editingMoment != null && composerUris.isEmpty() && count > 0 ? " · 选择新媒体可替换原内容" : ""));
        List<JsonObject> values = new ArrayList<>();
        if (composerUris.isEmpty()) {
            for (JsonElement value : existing) if (value.isJsonObject()) values.add(value.getAsJsonObject());
        } else {
            for (Uri uri : composerUris) {
                JsonObject value = new JsonObject();
                value.addProperty("url", uri.toString());
                value.addProperty("media_type", mime(uri).startsWith("video/") ? "video" : "image");
                values.add(value);
            }
        }
        composerBinding.mediaGrid.setAdapter(new MediaAdapter(values, (index, longPress) -> {
            if (!isUiActive() || index < 0 || index >= values.size()) return;
            if (longPress && !composerUris.isEmpty() && index >= 0 && index < composerUris.size()) {
                composerUris.remove(index);
                renderComposerMedia();
                return;
            }
            ImageGalleryActivity.open(this, values, index);
        }));
    }

    private void requestLocation() {
        if (!isUiActive() || composerBinding == null) return;
        if (ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED
            || ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_COARSE_LOCATION) == PackageManager.PERMISSION_GRANTED) {
            locate();
            return;
        }
        locationPermission.launch(new String[]{Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION});
    }

    private void locate() {
        if (!isUiActive() || composerBinding == null) return;
        composerBinding.locationButton.setEnabled(false);
        composerBinding.locationButton.setText("正在获取附近位置…");
        LocationManager manager = (LocationManager) getSystemService(LOCATION_SERVICE);
        if (manager == null) { locationFailed("无法使用定位服务"); return; }
        try {
            Location cached = bestLastLocation(manager);
            if (cached != null && System.currentTimeMillis() - cached.getTime() < 10 * 60_000L) {
                resolveLocation(cached);
                return;
            }
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
                String provider = manager.isProviderEnabled(LocationManager.GPS_PROVIDER)
                    ? LocationManager.GPS_PROVIDER : LocationManager.NETWORK_PROVIDER;
                manager.getCurrentLocation(provider, new CancellationSignal(), ContextCompat.getMainExecutor(this), location -> {
                    if (location == null) locationFailed("暂时无法获取位置，请检查系统定位开关");
                    else resolveLocation(location);
                });
            } else if (cached != null) resolveLocation(cached);
            else locationFailed("暂时无法获取位置，请稍后重试");
        } catch (SecurityException | IllegalArgumentException exception) {
            locationFailed("定位失败，请检查定位权限与系统定位开关");
        }
    }

    private Location bestLastLocation(LocationManager manager) {
        Location best = null;
        for (String provider : manager.getProviders(true)) {
            try {
                Location value = manager.getLastKnownLocation(provider);
                if (value != null && (best == null || value.getAccuracy() < best.getAccuracy())) best = value;
            } catch (SecurityException ignored) { }
        }
        return best;
    }

    private void resolveLocation(Location location) {
        double lat = location.getLatitude();
        double lng = location.getLongitude();
        new Thread(() -> {
            String label = String.format(Locale.CHINA, "附近位置 %.5f, %.5f", lat, lng);
            try {
                List<Address> addresses = new Geocoder(getApplicationContext(), Locale.CHINA).getFromLocation(lat, lng, 1);
                if (addresses != null && !addresses.isEmpty()) {
                    Address address = addresses.get(0);
                    StringBuilder value = new StringBuilder();
                    append(value, address.getLocality());
                    append(value, address.getSubLocality());
                    append(value, address.getThoroughfare());
                    append(value, address.getFeatureName());
                    if (value.length() > 0) label = value.toString();
                }
            } catch (Exception ignored) { }
            String finalLabel = label;
            runOnUiThread(() -> {
                if (composerBinding == null || isFinishing() || isDestroyed()) return;
                locationName = finalLabel;
                locationAddress = finalLabel;
                latitude = lat;
                longitude = lng;
                renderLocation();
            });
        }, "moment-location").start();
    }

    private static void append(StringBuilder value, String part) {
        if (part == null || part.trim().isEmpty()) return;
        String clean = part.trim();
        if (value.indexOf(clean) >= 0) return;
        if (value.length() > 0) value.append(' ');
        value.append(clean);
    }

    private void locationFailed(String value) {
        if (!isUiActive()) return;
        if (composerBinding != null) {
            composerBinding.locationButton.setEnabled(true);
            composerBinding.locationButton.setText("添加附近位置");
        }
        message(value);
    }

    private void renderLocation() {
        if (composerBinding == null) return;
        boolean selected = !locationName.isEmpty() && latitude != null && longitude != null;
        composerBinding.locationButton.setEnabled(true);
        composerBinding.locationButton.setText(
            selected ? locationName + " · 点击查看或调整" : "添加附近位置");
        composerBinding.locationClearButton.setVisibility(selected ? View.VISIBLE : View.GONE);
    }

    private void chooseVisibilityMode() {
        if (!isUiActive() || composerBinding == null) return;
        int checked = 0;
        for (int index = 0; index < VISIBILITY_VALUES.length; index++) {
            if (VISIBILITY_VALUES[index].equals(composerVisibilityMode)) checked = index;
        }
        new YiyunyingDialogBuilder(this)
            .setTitle("谁可以看")
            .setSingleChoiceItems(VISIBILITY_LABELS, checked, (dialog, which) -> {
                composerVisibilityMode = VISIBILITY_VALUES[which];
                renderVisibility();
                dialog.dismiss();
                if (MomentDisplayPolicy.requiresFriendSelection(composerVisibilityMode)
                    && composerVisibilityUserIds.isEmpty() && composerBinding != null) {
                    composerBinding.getRoot().post(this::openVisibilityFriendPicker);
                }
            })
            .setNegativeButton("取消", null)
            .show();
    }

    private void chooseVisibleDays() {
        if (!isUiActive() || composerBinding == null) return;
        int checked = 0;
        for (int index = 0; index < VISIBLE_DAY_VALUES.length; index++) {
            Integer value = VISIBLE_DAY_VALUES[index];
            if (value == null ? composerVisibleDays == null : value.equals(composerVisibleDays)) checked = index;
        }
        new YiyunyingDialogBuilder(this)
            .setTitle("可见时间")
            .setSingleChoiceItems(VISIBLE_DAY_LABELS, checked, (dialog, which) -> {
                composerVisibleDays = VISIBLE_DAY_VALUES[which];
                renderVisibility();
                dialog.dismiss();
            })
            .setNegativeButton("取消", null)
            .show();
    }

    private void renderVisibility() {
        if (composerBinding == null) return;
        int modeIndex = 0;
        for (int index = 0; index < VISIBILITY_VALUES.length; index++) {
            if (VISIBILITY_VALUES[index].equals(composerVisibilityMode)) modeIndex = index;
        }
        int dayIndex = 0;
        for (int index = 0; index < VISIBLE_DAY_VALUES.length; index++) {
            Integer value = VISIBLE_DAY_VALUES[index];
            if (value == null ? composerVisibleDays == null : value.equals(composerVisibleDays)) dayIndex = index;
        }
        composerBinding.visibilityModeButton.setText("谁可以看 · " + VISIBILITY_LABELS[modeIndex]);
        composerBinding.visibleDaysButton.setText("可见时间 · " + VISIBLE_DAY_LABELS[dayIndex]);
        boolean needsSelection = MomentDisplayPolicy.requiresFriendSelection(composerVisibilityMode);
        composerBinding.visibilityUsersButton.setVisibility(needsSelection ? View.VISIBLE : View.GONE);
        boolean excluded = "exclude".equals(composerVisibilityMode);
        String prefix = excluded ? "不让谁看" : "只让谁看";
        composerBinding.visibilityUsersButton.setText(composerVisibilityUserIds.isEmpty()
            ? prefix + " · 从好友列表选择"
            : prefix + " · 已选择 " + composerVisibilityUserIds.size() + " 位好友");
    }

    private void openVisibilityFriendPicker() {
        if (!isUiActive() || composerBinding == null) return;
        boolean excluded = "exclude".equals(composerVisibilityMode);
        visibilityFriendPicker.launch(FriendPickerActivity.pickerIntent(
            this,
            new ArrayList<>(composerVisibilityUserIds),
            excluded ? "选择不让谁看" : "选择让谁看"));
    }

    private static String safeVisibilityMode(String value) {
        for (String allowed : VISIBILITY_VALUES) if (allowed.equals(value)) return value;
        return "inherit";
    }

    private static String join(JsonArray values) {
        StringBuilder joined = new StringBuilder();
        for (JsonElement value : values) {
            if (value == null || value.isJsonNull()) continue;
            String token;
            try { token = value.getAsString().trim(); }
            catch (RuntimeException ignored) { continue; }
            if (token.isEmpty()) continue;
            if (joined.length() > 0) joined.append('，');
            joined.append(token);
        }
        return joined.toString();
    }

    private static JsonArray tokens(String value) {
        JsonArray values = new JsonArray();
        if (value == null || value.trim().isEmpty()) return values;
        for (String token : value.trim().split("[\\s,，;；]+")) {
            String clean = token.trim();
            if (!clean.isEmpty()) values.add(clean);
        }
        return values;
    }

    private void submitMoment() {
        if (composerBinding == null || actionRequest != null || uploadRequest != null) return;
        if (visibilityOnlyEdit) {
            submitVisibility();
            return;
        }
        String content = text(composerBinding.contentInput);
        int existingCount = editingMoment == null ? 0 : Jsons.array(editingMoment, "attachments").size();
        if (content.isEmpty() && composerUris.isEmpty() && existingCount == 0) {
            message("文字和图片或视频不能同时为空");
            return;
        }
        setComposerEnabled(false);
        uploadNext(0, new JsonArray(), content);
    }

    private void uploadNext(int index, JsonArray attachments, String content) {
        if (composerBinding == null) return;
        if (index >= composerUris.size()) { sendMoment(content, attachments); return; }
        Uri uri = composerUris.get(index);
        FileInfo info = fileInfo(uri);
        if (!(info.mime.startsWith("image/") || info.mime.startsWith("video/"))) {
            setComposerEnabled(true);
            message("动态只支持图片和视频");
            return;
        }
        Map<String, String> fields = new LinkedHashMap<>();
        fields.put("scene", "moment");
        uploadRequest = AppAccess.from(this).repository().upload("/api/user/uploads", info.name, info.mime,
            new ContentUriRequestBody(getContentResolver(), uri, info.mime, info.size), fields, result -> {
                uploadRequest = null;
                if (composerBinding == null || isFinishing() || isDestroyed()) return;
                if (result.isAuthenticationFailure()) { login(); return; }
                if (!result.isSuccessful()) {
                    setComposerEnabled(true);
                    message(result.message().isEmpty() ? "第 " + (index + 1) + " 个媒体上传失败" : result.message());
                    return;
                }
                JsonObject attachment = new JsonObject();
                attachment.addProperty("media_type", info.mime.startsWith("video/") ? "video" : "image");
                attachment.addProperty("upload_id", Jsons.longValue(result.dataObject(), "upload_id"));
                attachment.addProperty("file_name", info.name);
                attachment.addProperty("mime_type", info.mime);
                if (info.size > 0) attachment.addProperty("size_bytes", info.size);
                attachments.add(attachment);
                uploadNext(index + 1, attachments, content);
            });
    }

    private void sendMoment(String content, JsonArray attachments) {
        JsonObject body = new JsonObject();
        body.addProperty("content", content);
        if (!composerUris.isEmpty() || editingMoment == null) body.add("attachments", attachments);
        body.addProperty("location_name", locationName);
        body.addProperty("location_address", locationAddress);
        body.addProperty("visibility_mode", composerVisibilityMode);
        if (composerVisibleDays == null) body.add("visible_days", JsonNull.INSTANCE);
        else body.addProperty("visible_days", composerVisibleDays);
        body.add("visibility_user_ids", visibilityUserIdsJson());
        if (!locationName.isEmpty() && latitude != null && longitude != null) {
            body.addProperty("latitude", latitude);
            body.addProperty("longitude", longitude);
            body.addProperty("current_latitude", latitude);
            body.addProperty("current_longitude", longitude);
        }
        boolean editing = editingMoment != null;
        long id = editing ? Jsons.longValue(editingMoment, "id") : 0L;
        actionRequest = editing
            ? AppAccess.from(this).repository().put("/api/user/moments/" + id, body, result -> completeSubmit(result, true))
            : AppAccess.from(this).repository().post("/api/user/moments", body, result -> completeSubmit(result, false));
    }

    private void submitVisibility() {
        if (composerBinding == null || editingMoment == null || actionRequest != null) return;
        long id = Jsons.longValue(editingMoment, "id");
        if (id <= 0L) return;
        setComposerEnabled(false);
        JsonObject body = new JsonObject();
        body.addProperty("visibility_mode", composerVisibilityMode);
        if (composerVisibleDays == null) body.add("visible_days", JsonNull.INSTANCE);
        else body.addProperty("visible_days", composerVisibleDays);
        body.add("visibility_user_ids", visibilityUserIdsJson());
        actionRequest = AppAccess.from(this).repository().put(
            "/api/user/moments/" + id + "/visibility",
            body,
            result -> completeVisibilitySubmit(result));
    }

    private JsonArray visibilityUserIdsJson() {
        JsonArray values = new JsonArray();
        for (Long userId : composerVisibilityUserIds) if (userId != null && userId > 0L) values.add(userId);
        return values;
    }

    private void completeVisibilitySubmit(xyz.jjmxg.yiyunying.data.api.ApiResult result) {
        actionRequest = null;
        if (composerBinding == null || isFinishing() || isDestroyed()) return;
        if (result.isAuthenticationFailure()) { login(); return; }
        if (!result.isSuccessful()) {
            setComposerEnabled(true);
            message(result.message().isEmpty() ? "可见范围保存失败" : result.message());
            return;
        }
        BottomSheetDialog dialog = composerDialog;
        if (dialog != null) dialog.dismiss();
        message(result.message().isEmpty() ? "可见范围已更新" : result.message());
        load(false);
    }

    private void completeSubmit(xyz.jjmxg.yiyunying.data.api.ApiResult result, boolean editing) {
        actionRequest = null;
        if (composerBinding == null || isFinishing() || isDestroyed()) return;
        if (result.isAuthenticationFailure()) { login(); return; }
        if (!result.isSuccessful()) {
            setComposerEnabled(true);
            message(result.message().isEmpty() ? (editing ? "动态保存失败" : "动态发布失败") : result.message());
            return;
        }
        BottomSheetDialog dialog = composerDialog;
        if (dialog != null) dialog.dismiss();
        message(result.message().isEmpty() ? (editing ? "动态已更新" : "动态发布成功") : result.message());
        load();
    }

    private void setComposerEnabled(boolean enabled) {
        if (composerBinding == null) return;
        composerBinding.progress.setVisibility(enabled ? View.INVISIBLE : View.VISIBLE);
        composerBinding.publishButton.setEnabled(enabled);
        composerBinding.cancelButton.setEnabled(enabled);
        composerBinding.mediaButton.setEnabled(enabled);
        composerBinding.locationButton.setEnabled(enabled);
        composerBinding.locationClearButton.setEnabled(enabled);
        composerBinding.visibilityModeButton.setEnabled(enabled);
        composerBinding.visibleDaysButton.setEnabled(enabled);
        composerBinding.visibilityUsersButton.setEnabled(enabled);
        composerBinding.contentInput.setEnabled(enabled);
    }

    private void showMomentMenu(JsonObject item) {
        if (!isUiActive() || item == null) return;
        JsonObject snapshot = item.deepCopy();
        List<String> labels = new ArrayList<>();
        if (flag(snapshot, "can_pin")) labels.add(flag(snapshot, "is_pinned") ? "取消置顶" : "置顶动态");
        if (flag(snapshot, "can_edit")) labels.add("编辑内容");
        if (flag(snapshot, "can_edit_visibility")) labels.add("编辑可见范围");
        if (flag(snapshot, "can_hide")) labels.add(flag(snapshot, "is_hidden") ? "取消隐藏" : "隐藏动态");
        if (flag(snapshot, "can_delete")) labels.add("删除动态");
        if (labels.isEmpty()) return;
        new YiyunyingDialogBuilder(this).setTitle("动态操作")
            .setItems(labels.toArray(new String[0]), (dialog, which) -> {
                if (which < 0 || which >= labels.size()) return;
                String action = labels.get(which);
                long id = Jsons.longValue(snapshot, "id");
                dialog.dismiss();
                if (binding == null) return;
                binding.getRoot().postDelayed(() -> {
                    if (!isUiActive()) return;
                    if ("置顶动态".equals(action)) setMomentPinned(id, true);
                    else if ("取消置顶".equals(action)) setMomentPinned(id, false);
                    else if ("编辑内容".equals(action)) showComposer(snapshot);
                    else if ("编辑可见范围".equals(action)) showVisibilityEditor(snapshot);
                    else if ("隐藏动态".equals(action)) setMomentHidden(id, true);
                    else if ("取消隐藏".equals(action)) setMomentHidden(id, false);
                    else if ("删除动态".equals(action)) confirmDelete(snapshot);
                }, 80L);
            }).setNegativeButton("取消", null).show();
    }

    private void setMomentPinned(long id, boolean pinned) {
        if (!isUiActive() || actionRequest != null || id <= 0) return;
        ++listGeneration;
        if (listRequest != null) {
            listRequest.cancel();
            listRequest = null;
        }
        binding.progress.setVisibility(View.INVISIBLE);
        binding.swipeRefresh.setRefreshing(false);
        JsonObject body = new JsonObject();
        body.addProperty("pinned", pinned);
        actionRequest = AppAccess.from(this).repository().post("/api/user/moments/" + id + "/pin", body, result -> {
            actionRequest = null;
            if (binding == null || isFinishing() || isDestroyed()) return;
            if (result.isAuthenticationFailure()) { login(); return; }
            if (!result.isSuccessful()) {
                message(result.message().isEmpty() ? (pinned ? "置顶失败" : "取消置顶失败") : result.message());
                return;
            }
            JsonObject updated = Jsons.object(result.dataObject(), "moment");
            adapter.applyPinned(updated, id, pinned);
            message(result.message().isEmpty() ? (pinned ? "动态已置顶" : "已取消置顶") : result.message());
            binding.getRoot().postDelayed(() -> {
                if (isUiActive()) load(false);
            }, 120L);
        });
    }

    private void setMomentHidden(long id, boolean hidden) {
        if (!isUiActive() || actionRequest != null || id <= 0) return;
        ++listGeneration;
        if (listRequest != null) {
            listRequest.cancel();
            listRequest = null;
        }
        binding.progress.setVisibility(View.INVISIBLE);
        binding.swipeRefresh.setRefreshing(false);
        JsonObject body = new JsonObject();
        body.addProperty("hidden", hidden);
        actionRequest = AppAccess.from(this).repository().post("/api/user/moments/" + id + "/hide", body, result -> {
            actionRequest = null;
            if (binding == null || isFinishing() || isDestroyed()) return;
            if (result.isAuthenticationFailure()) { login(); return; }
            if (!result.isSuccessful()) {
                message(result.message().isEmpty() ? (hidden ? "隐藏失败" : "取消隐藏失败") : result.message());
                return;
            }
            message(result.message().isEmpty() ? (hidden ? "动态已隐藏" : "已取消隐藏") : result.message());
            binding.getRoot().postDelayed(() -> {
                if (isUiActive()) load(false);
            }, 120L);
        });
    }

    private void confirmDelete(JsonObject item) {
        if (!isUiActive()) return;
        new YiyunyingDialogBuilder(this).setTitle("删除动态")
            .setMessage("删除后可在 2 分钟内撤销，超过时间将永久删除。")
            .setNegativeButton("取消", null)
            .setPositiveButton("删除", (dialog, which) -> deleteMoment(item)).show();
    }

    private void deleteMoment(JsonObject item) {
        if (!isUiActive() || actionRequest != null || item == null) return;
        long id = Jsons.longValue(item, "id");
        actionRequest = AppAccess.from(this).repository().delete("/api/user/moments/" + id, new JsonObject(), result -> {
            actionRequest = null;
            if (binding == null || isFinishing() || isDestroyed()) return;
            if (!result.isSuccessful()) { message(result.message().isEmpty() ? "删除失败" : result.message()); return; }
            load();
            Snackbar.make(binding.getRoot(), "动态已删除，2 分钟内可以恢复", Snackbar.LENGTH_LONG)
                .setAction("撤销", view -> restoreMoment(id)).show();
        });
    }

    private void restoreMoment(long id) {
        if (!isUiActive() || actionRequest != null || id <= 0) return;
        actionRequest = AppAccess.from(this).repository().post("/api/user/moments/" + id + "/restore", new JsonObject(), result -> {
            actionRequest = null;
            if (binding == null || isFinishing() || isDestroyed()) return;
            message(result.isSuccessful() ? "动态已恢复" : (result.message().isEmpty() ? "恢复失败" : result.message()));
            if (result.isSuccessful()) load();
        });
    }

    private void toggleLike(JsonObject item) {
        if (!isUiActive() || actionRequest != null || item == null) return;
        long id = Jsons.longValue(item, "id");
        actionRequest = AppAccess.from(this).repository().post("/api/user/moments/" + id + "/like", new JsonObject(), result -> {
            actionRequest = null;
            if (binding == null || isFinishing() || isDestroyed()) return;
            if (result.isAuthenticationFailure()) { login(); return; }
            if (!result.isSuccessful()) { message(result.message().isEmpty() ? "点赞失败" : result.message()); return; }
            JsonObject data = result.dataObject();
            item.addProperty("is_liked", flag(data, "liked"));
            item.addProperty("like_count", Jsons.intValue(data, "like_count", 0));
            if (data.has("visible_likers")) item.add("visible_likers", data.get("visible_likers").deepCopy());
            if (data.has("like_visibility")) item.add("like_visibility", data.get("like_visibility").deepCopy());
            adapter.notifyById(id);
        });
    }

    private void toggleFavorite(JsonObject item) {
        if (!isUiActive() || actionRequest != null || item == null) return;
        long id = Jsons.longValue(item, "id");
        actionRequest = AppAccess.from(this).repository().post("/api/user/moments/" + id + "/favorite", new JsonObject(), result -> {
            actionRequest = null;
            if (binding == null || isFinishing() || isDestroyed()) return;
            if (result.isAuthenticationFailure()) { login(); return; }
            if (!result.isSuccessful()) { message(result.message().isEmpty() ? "收藏失败" : result.message()); return; }
            JsonObject data = result.dataObject();
            item.addProperty("is_favorited", flag(data, "favorited"));
            item.addProperty("favorite_count", Jsons.intValue(data, "favorite_count", 0));
            adapter.notifyById(id);
        });
    }

    private void showLikes(JsonObject item) {
        if (!isUiActive()) return;
        if (likesDialog != null && likesDialog.isShowing()) return;
        likesMoment = item;
        BottomSheetDialog dialog = new BottomSheetDialog(this);
        SheetMomentLikesBinding sheetBinding = SheetMomentLikesBinding.inflate(getLayoutInflater());
        likesDialog = dialog;
        likesBinding = sheetBinding;
        dialog.setContentView(sheetBinding.getRoot());
        dialog.setOnDismissListener(ignored -> {
            if (likesDialog != dialog) return;
            if (likesRequest != null) likesRequest.cancel();
            likesRequest = null;
            likesBinding = null;
            likesDialog = null;
            likesMoment = null;
        });
        GlassBottomSheet.prepare(dialog, this, 0.88f, false);
        dialog.show();
        loadLikes();
    }

    private void loadLikes() {
        if (likesBinding == null || likesMoment == null) return;
        if (likesRequest != null) likesRequest.cancel();
        likesBinding.progress.setVisibility(View.VISIBLE);
        likesBinding.likesContainer.removeAllViews();
        likesBinding.policyText.setText("正在读取点赞可见范围...");
        Map<String, String> query = new LinkedHashMap<>();
        query.put("page", "1");
        query.put("limit", "100");
        long momentId = Jsons.longValue(likesMoment, "id");
        likesRequest = AppAccess.from(this).repository().get(
            "/api/user/moments/" + momentId + "/likes", query, result -> {
                likesRequest = null;
                if (likesBinding == null || likesMoment == null || isFinishing() || isDestroyed()) return;
                likesBinding.progress.setVisibility(View.INVISIBLE);
                if (result.isAuthenticationFailure()) { login(); return; }
                if (!result.isSuccessful()) {
                    renderLikesEmpty(result.message().isEmpty() ? "点赞详情加载失败" : result.message());
                    return;
                }
                JsonObject data = result.dataObject();
                List<JsonObject> likers = result.objectItems();
                JsonObject visibility = Jsons.object(data, "like_visibility");
                JsonArray visible = new JsonArray();
                for (JsonObject liker : likers) visible.add(liker.deepCopy());
                likesMoment.add("visible_likers", visible);
                likesMoment.add("like_visibility", visibility.deepCopy());
                int total = Jsons.intValue(visibility, "total_count", Jsons.intValue(data, "total", likers.size()));
                likesMoment.addProperty("like_count", total);
                adapter.notifyById(momentId);
                likesBinding.titleText.setText("点赞详情 " + total);
                likesBinding.policyText.setText(likePolicyText(visibility));
                if (likers.isEmpty()) {
                    renderLikesEmpty(total > 0 ? "其余点赞者受隐私设置保护" : "还没有人点赞");
                } else {
                    for (JsonObject liker : likers) addLikerView(liker);
                }
            });
    }

    private String likePolicyText(JsonObject visibility) {
        String label = Jsons.string(visibility, "label");
        int hidden = Jsons.intValue(visibility, "hidden_count", 0);
        if (label.isEmpty()) label = "默认仅展示共同好友的点赞身份";
        return hidden > 0 ? label + "，另有 " + hidden + " 位点赞者未展示" : label;
    }

    private void renderLikesEmpty(String value) {
        if (likesBinding == null) return;
        TextView empty = new TextView(this);
        empty.setGravity(Gravity.CENTER);
        empty.setPadding(dp(12), dp(48), dp(12), dp(48));
        empty.setText(value);
        empty.setTextSize(14f);
        empty.setTextColor(ContextCompat.getColor(this, R.color.on_surface_variant));
        likesBinding.likesContainer.addView(empty,
            new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));
    }

    private void addLikerView(JsonObject liker) {
        if (likesBinding == null) return;
        MaterialCardView card = new MaterialCardView(this);
        card.setRadius(dp(8));
        card.setCardElevation(0f);
        card.setCardBackgroundColor(ContextCompat.getColor(this, R.color.surface_container_high));
        LinearLayout body = new LinearLayout(this);
        body.setOrientation(LinearLayout.HORIZONTAL);
        body.setGravity(Gravity.CENTER_VERTICAL);
        body.setPadding(dp(12), dp(9), dp(12), dp(9));

        ShapeableImageView avatar = circularAvatar(44);
        String avatarUrl = ImageLoader.get().absoluteUrl(this, Jsons.string(liker, "avatar"));
        ImageLoader.get().loadThumbnail(avatarUrl, avatar, R.drawable.ic_person);
        body.addView(avatar, new LinearLayout.LayoutParams(dp(44), dp(44)));

        LinearLayout copy = new LinearLayout(this);
        copy.setOrientation(LinearLayout.VERTICAL);
        copy.setPadding(dp(11), 0, 0, 0);
        TextView name = new TextView(this);
        name.setText(likerName(liker));
        name.setTextSize(15f);
        name.setTextColor(ContextCompat.getColor(this, R.color.on_surface));
        name.setSingleLine(true);
        name.setEllipsize(TextUtils.TruncateAt.END);
        TextView relation = new TextView(this);
        relation.setText(likerRelation(liker));
        relation.setTextSize(12f);
        relation.setTextColor(ContextCompat.getColor(this, R.color.on_surface_variant));
        relation.setPadding(0, dp(2), 0, 0);
        copy.addView(name, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));
        copy.addView(relation, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));
        body.addView(copy, new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f));
        card.addView(body);

        long userId = Jsons.longValue(liker, "user_id");
        View.OnClickListener profile = view -> {
            if (isUiActive() && userId > 0) UserProfileActivity.open(MomentTimelineActivity.this, userId);
        };
        card.setOnClickListener(profile);
        avatar.setOnClickListener(profile);
        LinearLayout.LayoutParams params = new LinearLayout.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT);
        params.setMargins(0, 0, 0, dp(7));
        likesBinding.likesContainer.addView(card, params);
    }

    private ShapeableImageView circularAvatar(int sizeDp) {
        ShapeableImageView avatar = new ShapeableImageView(this);
        avatar.setScaleType(ImageView.ScaleType.CENTER_CROP);
        avatar.setShapeAppearanceModel(ShapeAppearanceModel.builder()
            .setAllCornerSizes(dp(sizeDp) / 2f)
            .build());
        avatar.setContentDescription("用户头像");
        return avatar;
    }

    private String likerName(JsonObject liker) {
        String name = Jsons.string(liker, "display_name");
        if (name.isEmpty()) name = Jsons.string(liker, "nickname");
        if (name.isEmpty()) name = Jsons.string(liker, "account");
        return name.isEmpty() ? "用户" : name;
    }

    private String likerRelation(JsonObject liker) {
        if (flag(liker, "is_self")) return "我 · 已点赞";
        if (flag(liker, "is_common_friend")) return "共同好友 · 已点赞";
        if (flag(liker, "is_friend")) return "好友 · 已点赞";
        return "已点赞";
    }

    private void showComments(JsonObject item) {
        if (!isUiActive()) return;
        if (commentsDialog != null && commentsDialog.isShowing()) return;
        commentsMoment = item;
        replyingCommentId = 0L;
        commentsViewportHeight = -1;
        commentsViewportResizePosted = false;
        BottomSheetDialog dialog = new BottomSheetDialog(this);
        SheetMomentCommentsBinding sheetBinding = SheetMomentCommentsBinding.inflate(getLayoutInflater());
        commentsDialog = dialog;
        commentsBinding = sheetBinding;
        dialog.setContentView(sheetBinding.getRoot());
        xyz.jjmxg.yiyunying.ui.common.TopCenterDoubleTap.attach(
            sheetBinding.commentsStickyHeader, sheetBinding.commentsScroll);
        configureCommentEmojiPanel();
        commentsBinding.emojiButton.setOnClickListener(view -> toggleCommentEmojiPanel());
        commentsBinding.stickerButton.setOnClickListener(view -> loadCommentStickers());
        commentsBinding.voiceButton.setOnClickListener(view -> toggleCommentVoiceRecording());
        commentsBinding.voiceClearButton.setOnClickListener(view -> clearPendingCommentVoice());
        commentsBinding.sendButton.setOnClickListener(view -> submitComment());
        commentsBinding.commentInput.setOnEditorActionListener((view, actionId, event) -> {
            if (actionId != EditorInfo.IME_ACTION_SEND) return false;
            submitComment();
            return true;
        });
        commentsBinding.commentInput.setOnFocusChangeListener((view, hasFocus) -> {
            if (hasFocus) revealCommentComposer();
        });
        commentsBinding.replyHint.setOnClickListener(view -> clearReply());
        dialog.setOnDismissListener(ignored -> {
            if (commentsDialog != dialog) return;
            detachCommentsLayoutListener(sheetBinding);
            if (commentRequest != null) commentRequest.cancel();
            if (commentLikeRequest != null) commentLikeRequest.cancel();
            if (commentStickerRequest != null) commentStickerRequest.cancel();
            if (commentVoiceUploadRequest != null) commentVoiceUploadRequest.cancel();
            commentRequest = null;
            commentLikeRequest = null;
            commentStickerRequest = null;
            commentVoiceUploadRequest = null;
            if (commentVoiceRecorder != null) commentVoiceRecorder.cancel();
            deletePendingCommentVoice();
            BottomSheetDialog stickerDialog = commentStickerDialog;
            commentStickerDialog = null;
            if (stickerDialog != null) stickerDialog.dismiss();
            commentsBinding = null;
            commentsDialog = null;
            commentsMoment = null;
            replyingCommentId = 0L;
        });
        GlassBottomSheet.prepare(dialog, this, 0.90f, false);
        Window window = dialog.getWindow();
        if (window != null) {
            window.clearFlags(WindowManager.LayoutParams.FLAG_ALT_FOCUSABLE_IM);
            window.setSoftInputMode(
                WindowManager.LayoutParams.SOFT_INPUT_ADJUST_RESIZE
                    | WindowManager.LayoutParams.SOFT_INPUT_STATE_ALWAYS_HIDDEN);
        }
        dialog.show();
        // GlassBottomSheet deliberately disables clipping on its content host so generic
        // action sheets can draw shadows around their last row. A scrolling comment list is
        // different: without restoring clipping, several vendor renderers let a scrolled
        // comment child draw above the sticky title and even outside the rounded panel.
        sheetBinding.getRoot().setClipChildren(true);
        sheetBinding.getRoot().setClipToPadding(true);
        sheetBinding.commentsViewport.setClipChildren(true);
        sheetBinding.commentsViewport.setClipToPadding(true);
        sheetBinding.commentsScroll.setClipChildren(true);
        sheetBinding.commentsScroll.setClipToPadding(true);
        commentsLayoutListener = this::scheduleCommentsViewportResize;
        sheetBinding.getRoot().getViewTreeObserver().addOnGlobalLayoutListener(commentsLayoutListener);
        scheduleCommentsViewportResize();
        loadComments();
    }

    private void loadComments() {
        if (commentsBinding == null || commentsMoment == null) return;
        if (commentRequest != null) commentRequest.cancel();
        commentsBinding.progress.setVisibility(View.VISIBLE);
        commentsBinding.titleText.setText("评论");
        commentsBinding.commentCountText.setText("加载中");
        commentsBinding.commentsContainer.removeAllViews();
        scheduleCommentsViewportResize();
        Map<String, String> query = new LinkedHashMap<>();
        query.put("page", "1");
        query.put("limit", "100");
        long momentId = Jsons.longValue(commentsMoment, "id");
        commentRequest = AppAccess.from(this).repository().get("/api/user/moments/" + momentId + "/comments", query, result -> {
            commentRequest = null;
            if (commentsBinding == null || isFinishing() || isDestroyed()) return;
            commentsBinding.progress.setVisibility(View.INVISIBLE);
            if (result.isAuthenticationFailure()) { login(); return; }
            if (!result.isSuccessful()) {
                commentsBinding.commentCountText.setText("加载失败");
                renderCommentsError(result.message().isEmpty() ? "评论加载失败" : result.message());
                return;
            }
            List<JsonObject> comments = result.objectItems();
            commentsMoment.addProperty("comment_count", comments.size());
            adapter.notifyById(momentId);
            commentsBinding.titleText.setText("评论");
            commentsBinding.commentCountText.setText(comments.size() + " 条");
            if (comments.isEmpty()) renderCommentsError("还没有评论，来说两句吧");
            else renderCommentThreads(comments);
            scheduleCommentsViewportResize();
        });
    }

    private void renderCommentsError(String value) {
        if (commentsBinding == null) return;
        TextView empty = new TextView(this);
        empty.setGravity(Gravity.CENTER);
        empty.setPadding(dp(12), dp(44), dp(12), dp(44));
        empty.setText(value);
        empty.setTextSize(14f);
        commentsBinding.commentsContainer.addView(empty,
            new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));
        scheduleCommentsViewportResize();
    }

    private void renderCommentThreads(List<JsonObject> comments) {
        if (commentsBinding == null) return;
        Map<Long, JsonObject> byId = new LinkedHashMap<>();
        List<JsonObject> roots = new ArrayList<>();
        List<JsonObject> orphanReplies = new ArrayList<>();
        Map<Long, List<JsonObject>> repliesByRoot = new LinkedHashMap<>();
        for (JsonObject comment : comments) {
            long id = Jsons.longValue(comment, "id");
            if (id > 0L) byId.put(id, comment);
        }
        for (JsonObject comment : comments) {
            long parentId = Jsons.longValue(comment, "parent_id");
            if (parentId <= 0L) {
                roots.add(comment);
                continue;
            }
            long rootId = resolveMomentCommentRoot(comment, byId);
            JsonObject root = byId.get(rootId);
            if (root == null || Jsons.longValue(root, "parent_id") > 0L) {
                orphanReplies.add(comment);
                continue;
            }
            repliesByRoot.computeIfAbsent(rootId, ignored -> new ArrayList<>()).add(comment);
            if (targetCommentId > 0L && Jsons.longValue(comment, "id") == targetCommentId) {
                expandedMomentCommentThreads.add(rootId);
            }
        }

        for (JsonObject root : roots) {
            ItemMomentCommentBinding rootRow = addCommentView(
                root, commentsBinding.commentsContainer, false);
            long rootId = Jsons.longValue(root, "id");
            List<JsonObject> replies = repliesByRoot.get(rootId);
            if (replies == null || replies.isEmpty()) continue;
            rootRow.replyThreadContainer.setVisibility(View.VISIBLE);
            List<View> replyViews = new ArrayList<>();
            for (JsonObject reply : replies) {
                ItemMomentCommentBinding replyRow = addCommentView(
                    reply, rootRow.nestedRepliesContainer, true);
                replyViews.add(replyRow.getRoot());
            }
            Runnable refresh = () -> {
                boolean expanded = expandedMomentCommentThreads.contains(rootId);
                for (int index = 0; index < replyViews.size(); index++) {
                    replyViews.get(index).setVisibility(
                        expanded || index < 2 ? View.VISIBLE : View.GONE);
                }
                boolean toggleNeeded = replyViews.size() > 2;
                rootRow.replyThreadToggle.setVisibility(toggleNeeded ? View.VISIBLE : View.GONE);
                rootRow.replyThreadToggle.setText(expanded
                    ? "收起回复" : "展开全部 " + replyViews.size() + " 条回复");
                rootRow.replyThreadToggle.setContentDescription(
                    rootRow.replyThreadToggle.getText());
            };
            rootRow.replyThreadToggle.setOnClickListener(view -> {
                if (expandedMomentCommentThreads.contains(rootId)) {
                    expandedMomentCommentThreads.remove(rootId);
                } else {
                    expandedMomentCommentThreads.add(rootId);
                }
                refresh.run();
                scheduleCommentsViewportResize();
            });
            refresh.run();
        }
        for (JsonObject orphan : orphanReplies) {
            addCommentView(orphan, commentsBinding.commentsContainer, false);
        }
    }

    private long resolveMomentCommentRoot(JsonObject comment, Map<Long, JsonObject> byId) {
        long id = Jsons.longValue(comment, "id");
        long parentId = Jsons.longValue(comment, "parent_id");
        long rootId = id;
        java.util.Set<Long> visited = new java.util.HashSet<>();
        while (parentId > 0L && visited.add(parentId)) {
            rootId = parentId;
            JsonObject parent = byId.get(parentId);
            if (parent == null) break;
            parentId = Jsons.longValue(parent, "parent_id");
        }
        return rootId;
    }

    private ItemMomentCommentBinding addCommentView(
        JsonObject comment,
        LinearLayout parentContainer,
        boolean nested
    ) {
        if (commentsBinding == null) return null;
        ItemMomentCommentBinding row = ItemMomentCommentBinding.inflate(
            getLayoutInflater(), parentContainer, false);
        String author = Jsons.string(comment, "display_name");
        if (author.isEmpty()) author = Jsons.string(comment, "account");
        if (author.isEmpty()) author = "用户";
        String parent = Jsons.string(comment, "parent_display_name");
        RuntimeLanguage.setDynamicText(row.author, parent.isEmpty()
            ? author : author + " " + RuntimeLanguage.translate(this, "回复") + " " + parent);
        row.time.setText(commentTime(Jsons.string(comment, "created_at")));

        String parentContent = Jsons.string(comment, "parent_content");
        if (parent.isEmpty()) {
            row.replyContext.setVisibility(View.GONE);
        } else {
            row.replyContext.setVisibility(View.VISIBLE);
            RuntimeLanguage.setDynamicText(row.replyContext,
                "回复 @" + parent + (parentContent.isEmpty() ? "" : " · " + parentContent));
        }

        String content = Jsons.string(comment, "content");
        row.content.setVisibility(content.isEmpty() ? View.GONE : View.VISIBLE);
        if (!content.isEmpty()) RuntimeLanguage.setDynamicText(row.content, content);

        String stickerUrl = Jsons.string(comment, "sticker_url");
        if (stickerUrl.isEmpty()) stickerUrl = Jsons.string(comment, "sticker_thumbnail_url");
        row.sticker.setVisibility(stickerUrl.isEmpty() ? View.GONE : View.VISIBLE);
        if (!stickerUrl.isEmpty()) {
            ImageLoader.get().load(ImageLoader.get().absoluteUrl(this, stickerUrl),
                row.sticker, R.drawable.ic_emoji);
        }
        MediaViewRenderer.render(this, row.mediaContainer, Jsons.array(comment, "attachments"));

        long userId = Jsons.longValue(comment, "user_id");
        String avatarUrl = ImageLoader.get().absoluteUrl(this, Jsons.string(comment, "avatar"));
        ImageLoader.get().load(avatarUrl, row.avatar, R.drawable.ic_person);
        row.avatar.setOnClickListener(view -> {
            if (userId > 0) UserProfileActivity.open(this, userId);
        });

        renderCommentLike(row, comment);
        row.likeButton.setOnClickListener(view -> toggleCommentLike(row, comment));
        String finalAuthor = author;
        row.replyButton.setOnClickListener(view -> focusCommentReply(comment, finalAuthor));
        row.commentCard.setOnLongClickListener(view -> {
            if (!flag(comment, "can_delete")) return false;
            confirmDeleteComment(comment);
            return true;
        });
        if (nested) {
            row.commentCard.setStrokeWidth(0);
            row.commentCard.setRadius(dp(4));
            row.commentCard.setCardBackgroundColor(getColor(R.color.surface_container_high));
            ViewGroup.LayoutParams rawParams = row.getRoot().getLayoutParams();
            if (rawParams instanceof LinearLayout.LayoutParams) {
                LinearLayout.LayoutParams params = (LinearLayout.LayoutParams) rawParams;
                params.bottomMargin = dp(3);
                row.getRoot().setLayoutParams(params);
            }
            ViewGroup.LayoutParams avatarParams = row.avatar.getLayoutParams();
            avatarParams.width = dp(32);
            avatarParams.height = dp(32);
            row.avatar.setLayoutParams(avatarParams);
        }
        parentContainer.addView(row.getRoot());

        long commentId = Jsons.longValue(comment, "id");
        if (targetCommentId > 0 && targetCommentId == commentId) {
            row.commentCard.setStrokeWidth(dp(2));
            row.commentCard.setStrokeColor(
                xyz.jjmxg.yiyunying.ui.common.ThemeColors.primary(this));
            commentsBinding.commentsScroll.post(() -> {
                if (commentsBinding != null) {
                    Rect target = new Rect();
                    row.getRoot().getDrawingRect(target);
                    commentsBinding.commentsContainer.offsetDescendantRectToMyCoords(
                        row.getRoot(), target);
                    commentsBinding.commentsScroll.smoothScrollTo(
                        0, Math.max(0, target.top - dp(8)));
                }
            });
        }
        return row;
    }

    private void focusCommentReply(JsonObject comment, String author) {
        if (commentsBinding == null) return;
        replyingCommentId = Jsons.longValue(comment, "id");
        RuntimeLanguage.setDynamicText(commentsBinding.replyHint,
            RuntimeLanguage.translate(this, "正在回复 ") + author
                + RuntimeLanguage.translate(this, " · 点击取消"));
        commentsBinding.replyHint.setVisibility(View.VISIBLE);
        commentsBinding.emojiPanel.setVisibility(View.GONE);
        commentsBinding.emojiButton.setSelected(false);
        commentsBinding.commentInput.requestFocus();
        commentsBinding.commentInput.post(() -> {
            InputMethodManager manager = (InputMethodManager) getSystemService(INPUT_METHOD_SERVICE);
            if (manager != null && commentsBinding != null) {
                manager.showSoftInput(commentsBinding.commentInput, InputMethodManager.SHOW_IMPLICIT);
            }
            revealCommentComposer();
        });
    }

    private void revealCommentComposer() {
        SheetMomentCommentsBinding sheet = commentsBinding;
        if (sheet == null) return;
        scheduleCommentsViewportResize();
        sheet.getRoot().postDelayed(() -> {
            if (commentsBinding != sheet || isFinishing() || isDestroyed()) return;
            scheduleCommentsViewportResize();
            Rect inputBounds = new Rect();
            sheet.commentInput.getDrawingRect(inputBounds);
            sheet.commentInput.requestRectangleOnScreen(inputBounds, true);
        }, 160L);
    }

    private void scheduleCommentsViewportResize() {
        SheetMomentCommentsBinding sheet = commentsBinding;
        if (sheet == null || commentsViewportResizePosted) return;
        commentsViewportResizePosted = true;
        sheet.getRoot().post(() -> {
            commentsViewportResizePosted = false;
            if (commentsBinding != sheet || isFinishing() || isDestroyed()) return;
            resizeCommentsViewport(sheet);
        });
    }

    private void resizeCommentsViewport(SheetMomentCommentsBinding sheet) {
        int width = sheet.commentsScroll.getWidth();
        if (width <= 0) width = sheet.getRoot().getWidth();
        if (width <= 0) return;

        int contentWidth = Math.max(1, width
            - sheet.commentsScroll.getPaddingLeft() - sheet.commentsScroll.getPaddingRight());
        sheet.commentsContainer.measure(
            View.MeasureSpec.makeMeasureSpec(contentWidth, View.MeasureSpec.EXACTLY),
            View.MeasureSpec.makeMeasureSpec(0, View.MeasureSpec.UNSPECIFIED));
        int contentHeight = Math.max(dp(56), sheet.commentsContainer.getMeasuredHeight());

        int chromeHeight = sheet.getRoot().getPaddingTop() + sheet.getRoot().getPaddingBottom();
        int scrollMargins = 0;
        for (int index = 0; index < sheet.getRoot().getChildCount(); index++) {
            View child = sheet.getRoot().getChildAt(index);
            ViewGroup.LayoutParams raw = child.getLayoutParams();
            int verticalMargins = 0;
            if (raw instanceof ViewGroup.MarginLayoutParams) {
                ViewGroup.MarginLayoutParams margins = (ViewGroup.MarginLayoutParams) raw;
                verticalMargins = margins.topMargin + margins.bottomMargin;
            }
            if (child == sheet.commentsViewport) {
                scrollMargins = verticalMargins;
            } else if (child.getVisibility() != View.GONE) {
                chromeHeight += child.getMeasuredHeight() + verticalMargins;
            }
        }

        Rect visibleFrame = new Rect();
        sheet.getRoot().getWindowVisibleDisplayFrame(visibleFrame);
        int displayHeight = getResources().getDisplayMetrics().heightPixels;
        int visibleHeight = visibleFrame.height() > 0 ? visibleFrame.height() : displayHeight;
        int panelLimit = Math.min(visibleHeight - dp(16), Math.round(displayHeight * 0.90f));
        int availableForComments = Math.max(dp(56),
            panelLimit - chromeHeight - scrollMargins - dp(20));
        int targetHeight = Math.min(contentHeight, Math.min(dp(320), availableForComments));
        if (targetHeight == commentsViewportHeight
            && sheet.commentsScroll.getLayoutParams().height == targetHeight) return;

        commentsViewportHeight = targetHeight;
        ViewGroup.LayoutParams params = sheet.commentsScroll.getLayoutParams();
        params.height = targetHeight;
        sheet.commentsScroll.setLayoutParams(params);
        GlassBottomSheet.refresh(commentsDialog, this, 0.90f, false);
    }

    private void detachCommentsLayoutListener(SheetMomentCommentsBinding sheet) {
        ViewTreeObserver.OnGlobalLayoutListener listener = commentsLayoutListener;
        commentsLayoutListener = null;
        commentsViewportHeight = -1;
        commentsViewportResizePosted = false;
        if (sheet == null || listener == null) return;
        ViewTreeObserver observer = sheet.getRoot().getViewTreeObserver();
        if (observer.isAlive()) observer.removeOnGlobalLayoutListener(listener);
    }

    private static String commentTime(String value) {
        String normalized = value == null ? "" : value.trim().replace('T', ' ');
        return normalized.length() > 16 ? normalized.substring(0, 16) : normalized;
    }

    private void renderCommentLike(ItemMomentCommentBinding row, JsonObject comment) {
        int likeCount = Math.max(0, Jsons.intValue(comment, "like_count", 0));
        boolean liked = flag(comment, "is_liked");
        row.likeButton.setSelected(liked);
        row.likeButton.setText(actionLabel(liked ? "已赞" : "赞", likeCount));
    }

    private void toggleCommentLike(ItemMomentCommentBinding row, JsonObject comment) {
        if (commentsMoment == null || commentLikeRequest != null) return;
        long momentId = Jsons.longValue(commentsMoment, "id");
        long commentId = Jsons.longValue(comment, "id");
        if (momentId <= 0 || commentId <= 0) return;
        row.likeButton.setEnabled(false);
        commentLikeRequest = AppAccess.from(this).repository().post(
            "/api/user/moments/" + momentId + "/comments/" + commentId + "/like",
            new JsonObject(), result -> {
                commentLikeRequest = null;
                if (commentsBinding == null || isFinishing() || isDestroyed()) return;
                row.likeButton.setEnabled(true);
                if (!result.isSuccessful()) {
                    message(result.message().isEmpty() ? "评论点赞失败" : result.message());
                    return;
                }
                comment.addProperty("is_liked", flag(result.dataObject(), "liked"));
                comment.addProperty("like_count", Jsons.intValue(result.dataObject(), "like_count", 0));
                renderCommentLike(row, comment);
            });
    }

    private void configureCommentEmojiPanel() {
        if (commentsBinding == null) return;
        commentsBinding.emojiGrid.removeAllViews();
        for (String emoji : xyz.jjmxg.yiyunying.ui.common.EmojiCatalog.values()) {
            TextView item = new TextView(this);
            item.setText(emoji);
            item.setTextSize(22f);
            item.setGravity(Gravity.CENTER);
            item.setContentDescription("插入 " + emoji);
            GridLayout.LayoutParams params = new GridLayout.LayoutParams();
            params.width = dp(42);
            params.height = dp(42);
            params.setMargins(dp(2), dp(2), dp(2), dp(2));
            item.setLayoutParams(params);
            item.setOnClickListener(view -> {
                if (commentsBinding == null || commentsBinding.commentInput.getText() == null) return;
                int start = Math.max(0, commentsBinding.commentInput.getSelectionStart());
                commentsBinding.commentInput.getText().insert(start, emoji);
            });
            commentsBinding.emojiGrid.addView(item);
        }
        commentsBinding.commentInput.setOnFocusChangeListener((view, hasFocus) -> {
            if (hasFocus && commentsBinding != null) {
                commentsBinding.emojiPanel.setVisibility(View.GONE);
                commentsBinding.emojiButton.setSelected(false);
            }
        });
    }

    private void toggleCommentEmojiPanel() {
        if (commentsBinding == null) return;
        boolean show = commentsBinding.emojiPanel.getVisibility() != View.VISIBLE;
        commentsBinding.emojiPanel.setVisibility(show ? View.VISIBLE : View.GONE);
        commentsBinding.emojiButton.setSelected(show);
        if (show) {
            commentsBinding.commentInput.clearFocus();
            InputMethodManager manager = (InputMethodManager) getSystemService(INPUT_METHOD_SERVICE);
            if (manager != null) manager.hideSoftInputFromWindow(commentsBinding.commentInput.getWindowToken(), 0);
        }
    }

    private void loadCommentStickers() {
        if (commentsBinding == null || commentStickerRequest != null) return;
        commentsBinding.stickerButton.setEnabled(false);
        commentStickerRequest = AppAccess.from(this).repository().get(
            "/api/user/sticker-packs", new LinkedHashMap<>(), result -> {
                commentStickerRequest = null;
                if (commentsBinding == null || isFinishing() || isDestroyed()) return;
                commentsBinding.stickerButton.setEnabled(true);
                if (!result.isSuccessful()) {
                    message(result.message().isEmpty() ? "表情包加载失败" : result.message());
                    return;
                }
                showCommentStickerPicker(result.items());
            });
    }

    private void showCommentStickerPicker(JsonArray packs) {
        if (commentsBinding == null) return;
        BottomSheetDialog dialog = new BottomSheetDialog(this);
        LinearLayout root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setPadding(dp(18), dp(14), dp(18), dp(18));
        TextView title = new TextView(this);
        title.setText("选择表情包");
        title.setTextAppearance(com.google.android.material.R.style.TextAppearance_Material3_TitleLarge);
        root.addView(title, new LinearLayout.LayoutParams(-1, -2));
        ScrollView scroll = new ScrollView(this);
        GridLayout grid = new GridLayout(this);
        grid.setColumnCount(4);
        grid.setPadding(0, dp(10), 0, dp(10));
        int count = 0;
        for (JsonElement packElement : packs) {
            if (!packElement.isJsonObject()) continue;
            for (JsonElement stickerElement : Jsons.array(packElement.getAsJsonObject(), "stickers")) {
                if (!stickerElement.isJsonObject()) continue;
                JsonObject sticker = stickerElement.getAsJsonObject();
                long stickerId = Jsons.longValue(sticker, "id");
                if (stickerId <= 0) continue;
                MaterialCardView card = new MaterialCardView(this);
                GridLayout.LayoutParams params = new GridLayout.LayoutParams();
                params.width = dp(76);
                params.height = dp(88);
                params.setMargins(dp(4), dp(4), dp(4), dp(4));
                card.setLayoutParams(params);
                card.setRadius(dp(6));
                card.setCardElevation(0f);
                LinearLayout tile = new LinearLayout(this);
                tile.setGravity(Gravity.CENTER);
                tile.setOrientation(LinearLayout.VERTICAL);
                ImageView preview = new ImageView(this);
                preview.setScaleType(ImageView.ScaleType.FIT_CENTER);
                tile.addView(preview, new LinearLayout.LayoutParams(dp(58), dp(58)));
                TextView name = new TextView(this);
                String stickerName = Jsons.string(sticker, "name");
                name.setText(stickerName.isEmpty() ? "表情" : stickerName);
                name.setGravity(Gravity.CENTER);
                name.setSingleLine(true);
                tile.addView(name, new LinearLayout.LayoutParams(-1, dp(24)));
                card.addView(tile);
                String imageUrl = Jsons.string(sticker, "image_url");
                ImageLoader.get().load(ImageLoader.get().absoluteUrl(this, imageUrl), preview, R.drawable.ic_emoji);
                card.setOnClickListener(view -> {
                    dialog.dismiss();
                    submitComment(stickerId);
                });
                grid.addView(card);
                count++;
            }
        }
        if (count == 0) {
            TextView empty = new TextView(this);
            empty.setText("还没有可用表情包，可先在聊天表情管理中添加");
            empty.setPadding(dp(12), dp(28), dp(12), dp(28));
            grid.addView(empty);
        }
        scroll.addView(grid, new ScrollView.LayoutParams(-1, -2));
        root.addView(scroll, new LinearLayout.LayoutParams(-1, dp(300)));
        dialog.setContentView(root);
        dialog.setOnDismissListener(ignored -> {
            if (commentStickerDialog == dialog) commentStickerDialog = null;
        });
        commentStickerDialog = dialog;
        GlassBottomSheet.prepare(dialog, this, 0.65f, false);
        dialog.show();
    }

    private void clearReply() {
        replyingCommentId = 0L;
        if (commentsBinding != null) commentsBinding.replyHint.setVisibility(View.GONE);
    }

    private void submitComment() {
        submitComment(0L);
    }

    private void submitComment(long stickerId) {
        if (commentsBinding == null || commentsMoment == null || commentRequest != null
            || commentVoiceUploadRequest != null) return;
        if (commentVoiceRecorder != null && commentVoiceRecorder.isRecording()) {
            finishCommentVoiceRecording(false);
            message("录音已完成，再点发送即可发表");
            return;
        }
        String content = text(commentsBinding.commentInput);
        if (content.isEmpty() && stickerId <= 0 && pendingCommentVoice == null) {
            message("请输入评论内容，或添加表情包、语音");
            return;
        }
        JsonObject body = new JsonObject();
        body.addProperty("content", content);
        if (stickerId > 0) body.addProperty("sticker_id", stickerId);
        if (replyingCommentId > 0) body.addProperty("parent_id", replyingCommentId);
        setCommentComposerEnabled(false);
        CommentVoiceRecorder.Result voice = pendingCommentVoice;
        if (voice != null) {
            uploadCommentVoice(body, voice);
            return;
        }
        postCommentBody(body, null);
    }

    private void uploadCommentVoice(JsonObject commentBody, CommentVoiceRecorder.Result voice) {
        ContentUriRequestBody requestBody = new ContentUriRequestBody(
            getContentResolver(), voice.uri, "audio/mp4", voice.sizeBytes);
        Map<String, String> fields = new LinkedHashMap<>();
        fields.put("scene", "动态评论语音");
        commentVoiceUploadRequest = AppAccess.from(this).repository().upload(
            "/api/user/uploads", voice.file.getName(), "audio/mp4", requestBody, fields, result -> {
                commentVoiceUploadRequest = null;
                if (commentsBinding == null || isFinishing() || isDestroyed()) return;
                if (result.isAuthenticationFailure()) { setCommentComposerEnabled(true); login(); return; }
                if (!result.isSuccessful()) {
                    setCommentComposerEnabled(true);
                    message(result.message().isEmpty() ? "语音评论上传失败，可重试" : result.message());
                    return;
                }
                JsonObject attachment = new JsonObject();
                attachment.addProperty("media_type", "audio");
                attachment.addProperty("upload_id", Jsons.longValue(result.dataObject(), "upload_id"));
                attachment.addProperty("file_name", voice.file.getName());
                attachment.addProperty("mime_type", "audio/mp4");
                attachment.addProperty("size_bytes", voice.sizeBytes);
                attachment.addProperty("duration_ms", voice.durationMs);
                JsonObject metadata = new JsonObject();
                metadata.addProperty("audio_kind", "voice");
                JsonArray waveform = new JsonArray();
                for (Integer sample : voice.waveform) waveform.add(sample == null ? 0 : sample);
                metadata.add("waveform", waveform);
                attachment.add("metadata", metadata);
                JsonArray attachments = new JsonArray();
                attachments.add(attachment);
                commentBody.add("attachments", attachments);
                postCommentBody(commentBody, voice);
            });
    }

    private void postCommentBody(JsonObject body, CommentVoiceRecorder.Result sentVoice) {
        long momentId = Jsons.longValue(commentsMoment, "id");
        commentRequest = AppAccess.from(this).repository().post("/api/user/moments/" + momentId + "/comments", body, result -> {
            commentRequest = null;
            if (commentsBinding == null || isFinishing() || isDestroyed()) return;
            setCommentComposerEnabled(true);
            if (result.isAuthenticationFailure()) { login(); return; }
            if (!result.isSuccessful()) { message(result.message().isEmpty() ? "评论失败" : result.message()); return; }
            if (sentVoice != null && pendingCommentVoice == sentVoice) {
                deletePendingCommentVoice();
                resetCommentVoiceUi();
            }
            commentsBinding.commentInput.setText("");
            clearReply();
            loadComments();
        });
    }

    private void toggleCommentVoiceRecording() {
        if (commentsBinding == null || commentRequest != null || commentVoiceUploadRequest != null) return;
        if (commentVoiceRecorder != null && commentVoiceRecorder.isRecording()) {
            finishCommentVoiceRecording(false);
            return;
        }
        if (ContextCompat.checkSelfPermission(this, Manifest.permission.RECORD_AUDIO)
            != PackageManager.PERMISSION_GRANTED) {
            commentVoicePermission.launch(Manifest.permission.RECORD_AUDIO);
            return;
        }
        startCommentVoiceRecording();
    }

    private void startCommentVoiceRecording() {
        if (commentsBinding == null) return;
        if (commentVoiceRecorder == null) commentVoiceRecorder = new CommentVoiceRecorder(this);
        deletePendingCommentVoice();
        commentsBinding.emojiPanel.setVisibility(View.GONE);
        commentsBinding.emojiButton.setSelected(false);
        commentsBinding.commentInput.clearFocus();
        InputMethodManager manager = (InputMethodManager) getSystemService(INPUT_METHOD_SERVICE);
        if (manager != null) {
            manager.hideSoftInputFromWindow(commentsBinding.commentInput.getWindowToken(), 0);
        }
        try {
            commentVoiceRecorder.start(new CommentVoiceRecorder.Listener() {
                @Override public void onTick(long elapsedMs) {
                    if (commentsBinding == null) return;
                    commentsBinding.voiceStatus.setText(voiceDuration(elapsedMs) + " · 点击麦克风完成");
                }
                @Override public void onLimitReached() {
                    finishCommentVoiceRecording(true);
                }
            });
        } catch (Exception exception) {
            resetCommentVoiceUi();
            message("无法开始录音，请检查麦克风是否被其他应用占用");
            return;
        }
        commentsBinding.voiceStatusRow.setVisibility(View.VISIBLE);
        commentsBinding.voiceStatusTitle.setText("正在录音");
        commentsBinding.voiceStatus.setText("00:00 · 点击麦克风完成");
        commentsBinding.voiceStatusIcon.setImageResource(R.drawable.ic_mic_off);
        commentsBinding.voiceClearButton.setContentDescription("取消录音");
        commentsBinding.voiceClearButton.setVisibility(View.VISIBLE);
        commentsBinding.voiceButton.setIconResource(R.drawable.ic_mic_off);
        commentsBinding.voiceButton.setSelected(true);
        scheduleCommentsViewportResize();
    }

    private void finishCommentVoiceRecording(boolean limitReached) {
        if (commentVoiceRecorder == null || !commentVoiceRecorder.isRecording()) return;
        CommentVoiceRecorder.Result result = commentVoiceRecorder.stop();
        if (result == null) {
            resetCommentVoiceUi();
            message("录音时间太短，请至少录制 1 秒");
            return;
        }
        pendingCommentVoice = result;
        if (commentsBinding != null) {
            commentsBinding.voiceStatusRow.setVisibility(View.VISIBLE);
            commentsBinding.voiceStatusTitle.setText("语音待发送");
            commentsBinding.voiceStatus.setText(voiceDuration(result.durationMs)
                + (limitReached ? " · 已到 60 秒上限，点击发送发表" : " · 点击发送发表"));
            commentsBinding.voiceStatusIcon.setImageResource(R.drawable.ic_mic);
            commentsBinding.voiceClearButton.setContentDescription("删除待发送语音");
            commentsBinding.voiceClearButton.setVisibility(View.VISIBLE);
            commentsBinding.voiceButton.setIconResource(R.drawable.ic_mic);
            commentsBinding.voiceButton.setSelected(false);
            scheduleCommentsViewportResize();
        }
    }

    private void clearPendingCommentVoice() {
        if (commentVoiceRecorder != null && commentVoiceRecorder.isRecording()) {
            commentVoiceRecorder.cancel();
        }
        deletePendingCommentVoice();
        resetCommentVoiceUi();
    }

    private void deletePendingCommentVoice() {
        CommentVoiceRecorder.Result voice = pendingCommentVoice;
        pendingCommentVoice = null;
        if (voice != null) voice.delete();
    }

    private void resetCommentVoiceUi() {
        if (commentsBinding == null) return;
        commentsBinding.voiceStatusRow.setVisibility(View.GONE);
        commentsBinding.voiceClearButton.setVisibility(View.GONE);
        commentsBinding.voiceButton.setIconResource(R.drawable.ic_mic);
        commentsBinding.voiceButton.setSelected(false);
        scheduleCommentsViewportResize();
    }

    private void setCommentComposerEnabled(boolean enabled) {
        if (commentsBinding == null) return;
        commentsBinding.commentInput.setEnabled(enabled);
        commentsBinding.emojiButton.setEnabled(enabled);
        commentsBinding.stickerButton.setEnabled(enabled);
        commentsBinding.voiceButton.setEnabled(enabled);
        commentsBinding.voiceClearButton.setEnabled(enabled);
        commentsBinding.sendButton.setEnabled(enabled);
    }

    private static String voiceDuration(long durationMs) {
        long seconds = Math.max(0L, durationMs / 1000L);
        return String.format(Locale.CHINA, "%02d:%02d", seconds / 60L, seconds % 60L);
    }

    private void confirmDeleteComment(JsonObject comment) {
        new YiyunyingDialogBuilder(this)
            .setTitle("删除评论")
            .setMessage("确定删除这条评论吗？")
            .setNegativeButton("取消", null)
            .setPositiveButton("删除", (dialog, which) -> deleteComment(comment))
            .show();
    }

    private void deleteComment(JsonObject comment) {
        if (commentsMoment == null || commentRequest != null) return;
        long momentId = Jsons.longValue(commentsMoment, "id");
        long commentId = Jsons.longValue(comment, "id");
        commentRequest = AppAccess.from(this).repository().delete(
            "/api/user/moments/" + momentId + "/comments/" + commentId, new JsonObject(), result -> {
                commentRequest = null;
                if (commentsBinding == null || isFinishing() || isDestroyed()) return;
                if (!result.isSuccessful()) { message(result.message().isEmpty() ? "删除评论失败" : result.message()); return; }
                loadComments();
            });
    }

    private void forwardMoment(JsonObject item) {
        if (!isUiActive()) return;
        if (actionRequest != null) { message("正在处理上一项操作"); return; }
        new YiyunyingDialogBuilder(this)
            .setTitle("转发动态")
            .setItems(new String[]{"发送给好友", "发送到群聊", "发送到聊天室", "发送给在线客服", "系统分享"},
                (dialog, which) -> {
                    if (which == 0) {
                        pendingForwardMoment = item;
                        forwardFriendPicker.launch(SocialDirectoryActivity.pickFriendsIntent(
                            this, 1, "选择接收动态的好友", new long[0], "该好友不可选择"));
                    } else if (which == 1) {
                        pendingForwardMoment = item;
                        forwardGroupPicker.launch(SocialDirectoryActivity.pickGroupsIntent(
                            this, 1, "选择群聊", new long[0]));
                    } else if (which == 2) {
                        pendingForwardMoment = item;
                        forwardChatroomPicker.launch(SocialDirectoryActivity.pickChatroomsIntent(
                            this, 1, "选择聊天室", new long[0]));
                    } else if (which == 3) {
                        confirmForwardToService(item);
                    } else {
                        sendMomentForward(item, "external", 0, true);
                    }
                })
            .setNegativeButton("取消", null)
            .show();
    }

    private void confirmForwardToService(JsonObject item) {
        new YiyunyingDialogBuilder(this)
            .setTitle("发送给在线客服")
            .setMessage("确认把这条动态发送给在线客服吗？客服会看到动态内容和原发布者信息。")
            .setPositiveButton("确认发送", (dialog, which) ->
                sendMomentForward(item, "service", 0, false))
            .setNegativeButton("取消", null)
            .show();
    }

    private void sendMomentForward(JsonObject item, String targetType, long targetId, boolean shareExternally) {
        if (actionRequest != null) { message("正在发送动态"); return; }
        long id = Jsons.longValue(item, "id");
        JsonObject body = new JsonObject();
        body.addProperty("target_type", targetType);
        if (targetId > 0) body.addProperty("target_id", targetId);
        actionRequest = AppAccess.from(this).repository().post("/api/user/moments/" + id + "/forward", body, result -> {
            actionRequest = null;
            if (binding == null || isFinishing() || isDestroyed()) return;
            if (!result.isSuccessful()) { message(result.message().isEmpty() ? "转发失败" : result.message()); return; }
            item.addProperty("forward_count", Jsons.intValue(result.dataObject(), "forward_count", 0));
            adapter.notifyById(id);
            if (shareExternally) openSystemShare(item);
            else message("动态已发送");
        });
    }

    private JsonObject firstSelected(Intent data) {
        String raw = data.getStringExtra(SocialDirectoryActivity.EXTRA_SELECTED_ITEMS);
        if (raw == null || raw.trim().isEmpty()) return new JsonObject();
        try {
            JsonArray values = JsonParser.parseString(raw).getAsJsonArray();
            return values.isEmpty() || !values.get(0).isJsonObject()
                ? new JsonObject() : values.get(0).getAsJsonObject();
        } catch (RuntimeException ignored) {
            return new JsonObject();
        }
    }

    private void openSystemShare(JsonObject item) {
        if (!isUiActive()) return;
        String author = Jsons.string(item, "display_name");
        String content = Jsons.string(item, "content");
        Intent share = new Intent(Intent.ACTION_SEND);
        share.setType("text/plain");
        share.putExtra(Intent.EXTRA_TEXT, author + "的动态" + (content.isEmpty() ? "" : "\n" + content));
        startActivity(Intent.createChooser(share, "转发动态"));
    }

    private void login() {
        startActivity(new Intent(this, LoginActivity.class));
        finish();
    }

    private void message(String value) {
        View root = isUiActive() ? binding.getRoot() : null;
        if (root != null) Snackbar.make(root, value, Snackbar.LENGTH_LONG).show();
    }

    private FileInfo fileInfo(Uri uri) {
        String name = "moment_" + System.currentTimeMillis();
        long size = -1L;
        try (Cursor cursor = getContentResolver().query(uri, new String[]{OpenableColumns.DISPLAY_NAME, OpenableColumns.SIZE}, null, null, null)) {
            if (cursor != null && cursor.moveToFirst()) {
                int nameIndex = cursor.getColumnIndex(OpenableColumns.DISPLAY_NAME);
                int sizeIndex = cursor.getColumnIndex(OpenableColumns.SIZE);
                if (nameIndex >= 0 && !cursor.isNull(nameIndex)) name = cursor.getString(nameIndex);
                if (sizeIndex >= 0 && !cursor.isNull(sizeIndex)) size = cursor.getLong(sizeIndex);
            }
        } catch (RuntimeException ignored) { }
        String mime = mime(uri);
        if (!name.contains(".")) name += mime.startsWith("video/") ? ".mp4" : ".jpg";
        return new FileInfo(name, mime, size);
    }

    private String mime(Uri uri) {
        String value = getContentResolver().getType(uri);
        if (value != null && !value.isEmpty()) return value;
        String path = uri == null ? "" : uri.toString().toLowerCase(Locale.ROOT);
        if (path.endsWith(".mp4") || path.endsWith(".mov") || path.endsWith(".mkv")) return "video/mp4";
        if (path.endsWith(".gif")) return "image/gif";
        if (path.endsWith(".png")) return "image/png";
        return "image/jpeg";
    }

    private static String text(android.widget.TextView view) {
        return view == null || view.getText() == null ? "" : view.getText().toString().trim();
    }

    private static boolean flag(JsonObject value, String key) {
        try { return value != null && value.has(key) && value.get(key).getAsBoolean(); }
        catch (RuntimeException ignored) { return false; }
    }

    private static Double nullableDouble(JsonObject value, String key) {
        try { return value != null && value.has(key) && !value.get(key).isJsonNull() ? value.get(key).getAsDouble() : null; }
        catch (RuntimeException ignored) { return null; }
    }

    private static String actionLabel(String label, int count) {
        if (count <= 0) return label;
        String compact;
        if (count <= 999) compact = String.valueOf(count);
        else if (count < 10_000) compact = "999+";
        else if (count < 1_000_000) compact = (count / 10_000) + "万+";
        else compact = "99万+";
        return label + '\u00A0' + compact;
    }

    private static ArrayList<JsonObject> copyMoments(List<JsonObject> values) {
        ArrayList<JsonObject> copied = new ArrayList<>();
        if (values == null) return copied;
        for (JsonObject value : values) {
            if (value != null) copied.add(value.deepCopy());
        }
        return copied;
    }

    private ArrayList<JsonObject> prepareMomentsForDisplay(List<JsonObject> values) {
        ArrayList<JsonObject> prepared = copyMoments(values);
        for (JsonObject value : prepared) value.remove("section_label");
        if (targetMomentId > 0) return prepared;
        if (!profileTimeline) {
            prepared.sort(MomentTimelineActivity::compareMomentsByTime);
            return prepared;
        }
        prepared.sort(MomentTimelineActivity::compareMoments);
        boolean pinnedSectionAdded = false;
        for (JsonObject value : prepared) {
            if (flag(value, "is_pinned")) {
                if (!pinnedSectionAdded) {
                    value.addProperty("section_label", "置顶动态");
                    pinnedSectionAdded = true;
                }
            }
        }
        return prepared;
    }

    private static int compareMomentsByTime(JsonObject left, JsonObject right) {
        int created = Jsons.string(right, "created_at").compareTo(Jsons.string(left, "created_at"));
        if (created != 0) return created;
        return Long.compare(Jsons.longValue(right, "id"), Jsons.longValue(left, "id"));
    }
    private static int compareMoments(JsonObject left, JsonObject right) {
        boolean leftPinned = flag(left, "is_pinned");
        boolean rightPinned = flag(right, "is_pinned");
        if (leftPinned != rightPinned) return leftPinned ? -1 : 1;
        if (leftPinned) {
            long leftOrder = Jsons.longValue(left, "pin_order");
            long rightOrder = Jsons.longValue(right, "pin_order");
            if (leftOrder <= 0L) leftOrder = Long.MAX_VALUE;
            if (rightOrder <= 0L) rightOrder = Long.MAX_VALUE;
            int order = Long.compare(leftOrder, rightOrder);
            if (order != 0) return order;
        }
        int created = Jsons.string(right, "created_at").compareTo(Jsons.string(left, "created_at"));
        if (created != 0) return created;
        return Long.compare(Jsons.longValue(right, "id"), Jsons.longValue(left, "id"));
    }

    private int dp(int value) { return Math.round(value * getResources().getDisplayMetrics().density); }

    @Override protected void onDestroy() {
        ++listGeneration;
        searchHandler.removeCallbacksAndMessages(null);
        if (listRequest != null) listRequest.cancel();
        if (actionRequest != null) actionRequest.cancel();
        if (uploadRequest != null) uploadRequest.cancel();
        if (commentRequest != null) commentRequest.cancel();
        if (commentLikeRequest != null) commentLikeRequest.cancel();
        if (commentStickerRequest != null) commentStickerRequest.cancel();
        if (commentVoiceUploadRequest != null) commentVoiceUploadRequest.cancel();
        if (likesRequest != null) likesRequest.cancel();
        if (commentVoiceRecorder != null) commentVoiceRecorder.release();
        commentVoiceRecorder = null;
        deletePendingCommentVoice();
        BottomSheetDialog composer = composerDialog;
        BottomSheetDialog comments = commentsDialog;
        BottomSheetDialog commentStickers = commentStickerDialog;
        BottomSheetDialog likes = likesDialog;
        detachCommentsLayoutListener(commentsBinding);
        composerDialog = null;
        commentsDialog = null;
        commentStickerDialog = null;
        likesDialog = null;
        composerBinding = null;
        commentsBinding = null;
        likesBinding = null;
        editingMoment = null;
        commentsMoment = null;
        likesMoment = null;
        pendingForwardMoment = null;
        if (composer != null) {
            composer.setOnDismissListener(null);
            composer.dismiss();
        }
        if (comments != null) {
            comments.setOnDismissListener(null);
            comments.dismiss();
        }
        if (commentStickers != null) {
            commentStickers.setOnDismissListener(null);
            commentStickers.dismiss();
        }
        if (likes != null) {
            likes.setOnDismissListener(null);
            likes.dismiss();
        }
        binding = null;
        super.onDestroy();
    }

    private final class MomentAdapter extends RecyclerView.Adapter<MomentAdapter.Holder> {
        private final List<JsonObject> items = new ArrayList<>();
        private final RecyclerView.RecycledViewPool mediaViewPool = new RecyclerView.RecycledViewPool();

        void submit(List<JsonObject> values) {
            ArrayList<JsonObject> next = copyMoments(values);
            ArrayList<JsonObject> previous = new ArrayList<>(items);
            DiffUtil.DiffResult changes = DiffUtil.calculateDiff(new DiffUtil.Callback() {
                @Override public int getOldListSize() { return previous.size(); }
                @Override public int getNewListSize() { return next.size(); }
                @Override public boolean areItemsTheSame(int oldPosition, int newPosition) {
                    long oldId = Jsons.longValue(previous.get(oldPosition), "id");
                    long newId = Jsons.longValue(next.get(newPosition), "id");
                    return oldId > 0L && oldId == newId;
                }
                @Override public boolean areContentsTheSame(int oldPosition, int newPosition) {
                    return previous.get(oldPosition).equals(next.get(newPosition));
                }
            }, false);
            items.clear();
            items.addAll(next);
            changes.dispatchUpdatesTo(this);
        }

        boolean isEmpty() {
            return items.isEmpty();
        }

        void applyPinned(JsonObject updated, long id, boolean pinned) {
            ArrayList<JsonObject> next = copyMoments(items);
            boolean found = false;
            for (int index = 0; index < next.size(); index++) {
                JsonObject current = next.get(index);
                if (Jsons.longValue(current, "id") != id) continue;
                JsonObject replacement = updated != null && updated.size() > 0
                    ? updated.deepCopy() : current.deepCopy();
                replacement.addProperty("is_pinned", pinned);
                if (!pinned) replacement.addProperty("pin_order", 0);
                next.set(index, replacement);
                found = true;
                break;
            }
            if (!found) return;
            ArrayList<JsonObject> prepared = prepareMomentsForDisplay(next);
            if (binding != null && binding.recycler.isComputingLayout()) {
                binding.recycler.post(() -> {
                    if (isUiActive()) submit(prepared);
                });
            } else {
                submit(prepared);
            }
        }

        void notifyById(long id) {
            for (int index = 0; index < items.size(); index++) {
                if (Jsons.longValue(items.get(index), "id") == id) {
                    notifyItemChanged(index);
                    return;
                }
            }
        }
        @NonNull @Override public Holder onCreateViewHolder(@NonNull ViewGroup parent, int viewType) {
            return new Holder(ItemMomentTimelineBinding.inflate(LayoutInflater.from(parent.getContext()), parent, false));
        }
        @Override public void onBindViewHolder(@NonNull Holder holder, int position) { holder.bind(items.get(position)); }
        @Override public int getItemCount() { return items.size(); }

        final class Holder extends RecyclerView.ViewHolder {
            final ItemMomentTimelineBinding row;
            Holder(ItemMomentTimelineBinding row) {
                super(row.getRoot());
                this.row = row;
                row.mediaGrid.setNestedScrollingEnabled(false);
                row.mediaGrid.setItemViewCacheSize(MAX_MEDIA);
                row.mediaGrid.setItemAnimator(null);
                row.mediaGrid.setRecycledViewPool(mediaViewPool);
            }
            void bind(JsonObject item) {
                String sectionLabel = Jsons.string(item, "section_label");
                RuntimeLanguage.setDynamicText(row.sectionHeader, sectionLabel);
                row.sectionHeader.setVisibility(sectionLabel.isEmpty() ? View.GONE : View.VISIBLE);
                row.dayText.setText(String.valueOf(Jsons.intValue(item, "day", 0)));
                row.monthText.setText(Jsons.intValue(item, "month", 0) + "月\n" + Jsons.intValue(item, "year", 0));
                row.authorName.setText(Jsons.string(item, "display_name"));
                String title = Jsons.string(item, "user_title");
                RuntimeLanguage.setDynamicText(row.authorTitle, title);
                row.authorTitle.setVisibility(title.isEmpty() ? View.GONE : View.VISIBLE);
                String content = Jsons.string(item, "content");
                RuntimeLanguage.setDynamicText(row.contentText, content);
                row.contentText.setVisibility(content.isEmpty() ? View.GONE : View.VISIBLE);
                String location = Jsons.string(item, "location_name");
                RuntimeLanguage.setDynamicText(row.locationText, location.isEmpty() ? "" : "位置 · " + location);
                row.locationText.setVisibility(location.isEmpty() ? View.GONE : View.VISIBLE);
                Double itemLatitude = nullableDouble(item, "latitude");
                Double itemLongitude = nullableDouble(item, "longitude");
                String itemAddress = Jsons.string(item, "location_address");
                if (itemAddress.isEmpty()) itemAddress = location;
                boolean canOpenLocation = !location.isEmpty()
                    && itemLatitude != null && itemLongitude != null;
                row.locationText.setClickable(canOpenLocation);
                row.locationText.setFocusable(canOpenLocation);
                String finalItemAddress = itemAddress;
                row.locationText.setOnClickListener(canOpenLocation
                    ? view -> LocationPickerActivity.openPreview(
                        MomentTimelineActivity.this,
                        location,
                        finalItemAddress,
                        itemLatitude,
                        itemLongitude)
                    : null);
                boolean showProfileSections = MomentDisplayPolicy.showsProfileSections(targetMomentId, profileTimeline);
                row.pinBadge.setVisibility(showProfileSections && flag(item, "is_pinned") ? View.VISIBLE : View.GONE);
                int currentPosition = getBindingAdapterPosition();
                int next = currentPosition == RecyclerView.NO_POSITION ? items.size() : currentPosition + 1;
                boolean hasNext = next < items.size();
                boolean nextIsPinned = hasNext && flag(items.get(next), "is_pinned");
                boolean showPinnedDivider = MomentDisplayPolicy.showsPinnedDivider(
                    showProfileSections,
                    flag(item, "is_pinned"),
                    hasNext,
                    nextIsPinned);
                row.pinDivider.setVisibility(showPinnedDivider ? View.VISIBLE : View.GONE);
                boolean immersive = targetMomentId > 0;
                row.dayText.setVisibility(immersive ? View.GONE : View.VISIBLE);
                row.monthText.setVisibility(immersive ? View.GONE : View.VISIBLE);
                StringBuilder meta = new StringBuilder();
                meta.append(Jsons.string(item, "time_label"));
                if (flag(item, "is_edited")) meta.append(" · 已编辑");
                row.metaText.setText(meta.toString());
                String avatar = ImageLoader.get().absoluteUrl(MomentTimelineActivity.this, Jsons.string(item, "avatar"));
                ImageLoader.get().loadThumbnail(avatar, row.avatar, R.drawable.ic_person);
                long userId = Jsons.longValue(item, "user_id");
                long momentId = Jsons.longValue(item, "id");
                View.OnClickListener profile = view -> {
                    if (isUiActive() && userId > 0) {
                        UserProfileActivity.open(MomentTimelineActivity.this, userId);
                    }
                };
                boolean opensDetail = targetMomentId <= 0 && momentId > 0;
                View.OnClickListener detail = view -> {
                    if (isUiActive()) {
                        openMoment(MomentTimelineActivity.this, momentId, userId, Jsons.string(item, "display_name"));
                    }
                };
                row.momentCard.setClickable(opensDetail);
                row.momentCard.setFocusable(opensDetail);
                row.momentCard.setOnClickListener(opensDetail ? detail : null);
                row.detailHint.setVisibility(opensDetail ? View.VISIBLE : View.GONE);
                row.detailHint.setOnClickListener(opensDetail ? detail : null);
                boolean hideRepeatedAuthor = profileTimeline;
                row.avatar.setVisibility(hideRepeatedAuthor ? View.GONE : View.VISIBLE);
                row.authorArea.setVisibility(hideRepeatedAuthor ? View.GONE : View.VISIBLE);
                row.avatar.setOnClickListener(profile);
                row.authorArea.setOnClickListener(profile);
                boolean manageable = MomentDisplayPolicy.isManageable(
                    flag(item, "can_pin"),
                    flag(item, "can_edit"),
                    flag(item, "can_edit_visibility"),
                    flag(item, "can_hide"),
                    flag(item, "can_delete"));
                row.moreButton.setVisibility(manageable ? View.VISIBLE : View.GONE);
                row.moreButton.setOnClickListener(view -> showMomentMenu(item.deepCopy()));
                List<JsonObject> media = new ArrayList<>();
                for (JsonElement value : Jsons.array(item, "attachments")) if (value.isJsonObject()) media.add(value.getAsJsonObject());
                row.mediaGrid.setAdapter(null);
                row.mediaGrid.setVisibility(media.isEmpty() ? View.GONE : View.VISIBLE);
                if (!media.isEmpty()) {
                    int columns = media.size() == 1 ? 1 : media.size() == 2 || media.size() == 4 ? 2 : 3;
                    RecyclerView.LayoutManager manager = row.mediaGrid.getLayoutManager();
                    if (!(manager instanceof GridLayoutManager)
                        || ((GridLayoutManager) manager).getSpanCount() != columns) {
                        row.mediaGrid.setLayoutManager(new GridLayoutManager(MomentTimelineActivity.this, columns));
                    }
                    row.mediaGrid.setAdapter(new MediaAdapter(media, (index, longPress) -> {
                        if (isUiActive() && index >= 0 && index < media.size()) {
                            ImageGalleryActivity.open(MomentTimelineActivity.this, media, index);
                        }
                    }));
                }
                int likeCount = Jsons.intValue(item, "like_count", 0);
                int commentCount = Jsons.intValue(item, "comment_count", 0);
                int favoriteCount = Jsons.intValue(item, "favorite_count", 0);
                int forwardCount = Jsons.intValue(item, "forward_count", 0);
                renderLikeSummary(row, item, likeCount);
                row.momentLikeButton.setText(actionLabel(flag(item, "is_liked") ? "已赞" : "点赞", likeCount));
                row.momentCommentButton.setText(actionLabel("评论", commentCount));
                row.momentFavoriteButton.setText(actionLabel(flag(item, "is_favorited") ? "已收藏" : "收藏", favoriteCount));
                row.momentForwardButton.setText(actionLabel("转发", forwardCount));
                row.momentLikeButton.setOnClickListener(view -> toggleLike(item));
                row.momentCommentButton.setOnClickListener(view -> showComments(item));
                row.momentFavoriteButton.setOnClickListener(view -> toggleFavorite(item));
                row.momentForwardButton.setOnClickListener(view -> forwardMoment(item));
            }

            private void renderLikeSummary(ItemMomentTimelineBinding row, JsonObject item, int likeCount) {
                row.likeAvatarContainer.removeAllViews();
                row.likeSummaryArea.setVisibility(likeCount > 0 ? View.VISIBLE : View.GONE);
                if (likeCount <= 0) return;
                JsonArray likers = Jsons.array(item, "visible_likers");
                ArrayList<String> names = new ArrayList<>();
                int previewCount = Math.min(likers.size(), 8);
                for (int index = 0; index < previewCount; index++) {
                    JsonElement value = likers.get(index);
                    if (!value.isJsonObject()) continue;
                    JsonObject liker = value.getAsJsonObject();
                    names.add(likerName(liker));
                    addLikerPreview(row, liker);
                }
                JsonObject visibility = Jsons.object(item, "like_visibility");
                int hidden = Jsons.intValue(visibility, "hidden_count", Math.max(0, likeCount - likers.size()));
                String summary;
                if (names.isEmpty()) {
                    summary = "共 " + likeCount + " 个赞，点赞者身份受隐私设置保护";
                } else {
                    String visibleNames = TextUtils.join("、", names.subList(0, Math.min(names.size(), 3)));
                    summary = visibleNames + (names.size() > 3 ? " 等" : "") + "点了赞";
                    if (hidden > 0) summary += " · 共 " + likeCount + " 个赞";
                }
                row.likeSummaryText.setText(summary);
                row.likeSummaryArea.setOnClickListener(view -> showLikes(item));
            }

            private void addLikerPreview(ItemMomentTimelineBinding row, JsonObject liker) {
                LinearLayout person = new LinearLayout(MomentTimelineActivity.this);
                person.setOrientation(LinearLayout.VERTICAL);
                person.setGravity(Gravity.CENTER_HORIZONTAL);
                person.setPadding(0, 0, dp(7), 0);
                ShapeableImageView avatar = circularAvatar(34);
                String avatarUrl = ImageLoader.get().absoluteUrl(
                    MomentTimelineActivity.this, Jsons.string(liker, "avatar"));
                ImageLoader.get().loadThumbnail(avatarUrl, avatar, R.drawable.ic_person);
                person.addView(avatar, new LinearLayout.LayoutParams(dp(34), dp(34)));
                TextView name = new TextView(MomentTimelineActivity.this);
                name.setText(likerName(liker));
                name.setTextSize(9f);
                name.setTextColor(ContextCompat.getColor(MomentTimelineActivity.this, R.color.on_surface_variant));
                name.setGravity(Gravity.CENTER);
                name.setSingleLine(true);
                name.setEllipsize(TextUtils.TruncateAt.END);
                LinearLayout.LayoutParams nameParams = new LinearLayout.LayoutParams(dp(44), ViewGroup.LayoutParams.WRAP_CONTENT);
                nameParams.topMargin = dp(2);
                person.addView(name, nameParams);
                long userId = Jsons.longValue(liker, "user_id");
                person.setOnClickListener(view -> {
                    if (isUiActive() && userId > 0) UserProfileActivity.open(MomentTimelineActivity.this, userId);
                });
                row.likeAvatarContainer.addView(person,
                    new LinearLayout.LayoutParams(dp(51), ViewGroup.LayoutParams.WRAP_CONTENT));
            }
        }
    }

    private final class MediaAdapter extends RecyclerView.Adapter<MediaAdapter.Holder> {
        interface Listener { void onClick(int index, boolean longPress); }
        private final List<JsonObject> media;
        private final Listener listener;
        MediaAdapter(List<JsonObject> media, Listener listener) { this.media = media; this.listener = listener; }
        @NonNull @Override public Holder onCreateViewHolder(@NonNull ViewGroup parent, int viewType) {
            return new Holder(ItemMomentMediaBinding.inflate(LayoutInflater.from(parent.getContext()), parent, false));
        }
        @Override public void onBindViewHolder(@NonNull Holder holder, int position) { holder.bind(media.get(position)); }
        @Override public int getItemCount() { return media.size(); }
        final class Holder extends RecyclerView.ViewHolder {
            final ItemMomentMediaBinding row;
            Holder(ItemMomentMediaBinding row) { super(row.getRoot()); this.row = row; }
            void bind(JsonObject value) {
                boolean video = "video".equals(Jsons.string(value, "media_type")) || Jsons.string(value, "mime_type").startsWith("video/");
                row.videoBadge.setVisibility(video ? View.VISIBLE : View.GONE);
                String preview = Jsons.string(value, "thumbnail_url");
                if (preview.isEmpty()) preview = Jsons.string(value, "url");
                if (!(preview.startsWith("content://") || preview.startsWith("file://"))) {
                    preview = ImageLoader.get().absoluteUrl(MomentTimelineActivity.this, preview);
                }
                ImageLoader.get().load(preview, row.mediaImage, video ? R.drawable.ic_play : R.drawable.ic_album);
                int count = media.size();
                ViewGroup.LayoutParams params = row.getRoot().getLayoutParams();
                params.height = dp(count == 1 ? 220 : count == 2 || count == 4 ? 150 : 104);
                row.getRoot().setLayoutParams(params);
                row.getRoot().setOnClickListener(view -> {
                    int current = getBindingAdapterPosition();
                    if (current == RecyclerView.NO_POSITION || current < 0 || current >= media.size()) return;
                    listener.onClick(current, false);
                });
                row.getRoot().setOnLongClickListener(view -> {
                    int current = getBindingAdapterPosition();
                    if (current != RecyclerView.NO_POSITION && current >= 0 && current < media.size()) {
                        listener.onClick(current, true);
                    }
                    return true;
                });
            }
        }
    }

    private static final class FileInfo {
        final String name;
        final String mime;
        final long size;
        FileInfo(String name, String mime, long size) { this.name = name; this.mime = mime; this.size = size; }
    }
}
