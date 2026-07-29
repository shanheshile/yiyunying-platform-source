package xyz.jjmxg.yiyunying.ui.moment;

import android.Manifest;
import android.content.Context;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.database.Cursor;
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
import android.view.inputmethod.EditorInfo;
import android.widget.LinearLayout;
import android.widget.ImageView;
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
import xyz.jjmxg.yiyunying.databinding.ItemMomentMediaBinding;
import xyz.jjmxg.yiyunying.databinding.ItemMomentTimelineBinding;
import xyz.jjmxg.yiyunying.databinding.SheetMomentCommentsBinding;
import xyz.jjmxg.yiyunying.databinding.SheetMomentComposerBinding;
import xyz.jjmxg.yiyunying.databinding.SheetMomentLikesBinding;
import xyz.jjmxg.yiyunying.ui.auth.LoginActivity;
import xyz.jjmxg.yiyunying.ui.common.GlassBottomSheet;
import xyz.jjmxg.yiyunying.ui.common.ImageLoader;
import xyz.jjmxg.yiyunying.ui.common.SystemInsetActivity;
import xyz.jjmxg.yiyunying.ui.common.YiyunyingDialogBuilder;
import xyz.jjmxg.yiyunying.ui.location.LocationPickerActivity;
import xyz.jjmxg.yiyunying.ui.profile.UserProfileActivity;
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
    private final Runnable delayedSearch = this::load;
    private ActivityMomentTimelineBinding binding;
    private MomentAdapter adapter;
    private long listGeneration;
    private RequestHandle listRequest;
    private RequestHandle actionRequest;
    private RequestHandle uploadRequest;
    private RequestHandle commentRequest;
    private RequestHandle likesRequest;
    private BottomSheetDialog composerDialog;
    private SheetMomentComposerBinding composerBinding;
    private BottomSheetDialog commentsDialog;
    private SheetMomentCommentsBinding commentsBinding;
    private BottomSheetDialog likesDialog;
    private SheetMomentLikesBinding likesBinding;
    private JsonObject editingMoment;
    private JsonObject commentsMoment;
    private JsonObject likesMoment;
    private JsonObject pendingForwardMoment;
    private long replyingCommentId;
    private long targetUserId;
    private long targetMomentId;
    private String targetUserTitle = "";
    private String composerVisibilityMode = "inherit";
    private Integer composerVisibleDays;
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

    private final ActivityResultLauncher<Intent> forwardRoomPicker = registerForActivityResult(
        new ActivityResultContracts.StartActivityForResult(), result -> {
            JsonObject moment = pendingForwardMoment;
            pendingForwardMoment = null;
            if (!isUiActive() || result.getResultCode() != RESULT_OK
                || result.getData() == null || moment == null) return;
            JsonObject selected = firstSelected(result.getData());
            long roomId = Jsons.longValue(selected, "id");
            if (roomId <= 0) { message("没有取得有效的群聊或聊天室编号"); return; }
            sendMomentForward(moment, "group", roomId, false);
        });

    private final ActivityResultLauncher<String[]> locationPermission = registerForActivityResult(
        new ActivityResultContracts.RequestMultiplePermissions(), result -> {
            if (!isUiActive()) return;
            boolean granted = Boolean.TRUE.equals(result.get(Manifest.permission.ACCESS_FINE_LOCATION))
                || Boolean.TRUE.equals(result.get(Manifest.permission.ACCESS_COARSE_LOCATION));
            if (granted) locate();
            else message("需要定位权限才能添加附近位置");
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
        context.startActivity(new Intent(context, MomentTimelineActivity.class)
            .putExtra(EXTRA_USER_ID, userId)
            .putExtra(EXTRA_USER_TITLE, title == null ? "" : title));
    }

    public static void openMoment(Context context, long momentId, long userId, String title) {
        if (momentId <= 0) return;
        context.startActivity(new Intent(context, MomentTimelineActivity.class)
            .putExtra(EXTRA_MOMENT_ID, momentId)
            .putExtra(EXTRA_USER_ID, Math.max(0L, userId))
            .putExtra(EXTRA_USER_TITLE, title == null ? "" : title));
    }

    private boolean isUiActive() {
        return binding != null && !isFinishing() && !isDestroyed();
    }

    @Override protected void onCreate(Bundle state) {
        super.onCreate(state);
        targetUserId = getIntent().getLongExtra(EXTRA_USER_ID, 0L);
        targetMomentId = getIntent().getLongExtra(EXTRA_MOMENT_ID, 0L);
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
        binding.recycler.setHasFixedSize(true);
        binding.recycler.setItemViewCacheSize(8);
        binding.recycler.setItemAnimator(null);
        binding.recycler.setAdapter(adapter);
        binding.swipeRefresh.setOnRefreshListener(this::load);
        binding.createButton.setOnClickListener(view -> showComposer(null));
        boolean ownTimeline = targetUserId <= 0 || targetUserId == AppAccess.from(this).session().actorId();
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
        if (targetUserId > 0) query.put("user_id", String.valueOf(targetUserId));
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
        ArrayList<JsonObject> snapshot = copyMoments(values);
        Runnable render = () -> {
            if (!isUiActive() || adapter == null || generation != listGeneration) return;
            adapter.submit(snapshot);
            binding.emptyText.setVisibility(snapshot.isEmpty() ? View.VISIBLE : View.GONE);
        };
        if (binding.recycler.isComputingLayout()) binding.recycler.post(render);
        else render.run();
    }

    private void showComposer(JsonObject item) {
        if (!isUiActive()) return;
        if (composerDialog != null && composerDialog.isShowing()) return;
        editingMoment = item == null ? null : item.deepCopy();
        composerUris.clear();
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
        composerBinding.titleText.setText(item == null ? "发布动态" : "编辑动态");
        composerBinding.publishButton.setText(item == null ? "发布" : "保存");
        if (item != null) composerBinding.contentInput.setText(Jsons.string(item, "content"));
        if (item != null) composerBinding.visibilityUsersInput.setText(join(Jsons.array(item, "visibility_user_ids")));
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
        boolean selected = "selected".equals(composerVisibilityMode);
        boolean excluded = "exclude".equals(composerVisibilityMode);
        composerBinding.visibilityUsersLayout.setVisibility(selected || excluded ? View.VISIBLE : View.GONE);
        composerBinding.visibilityUsersLayout.setHint(selected
            ? "允许查看的 UID 或账号，用逗号分隔"
            : "不允许查看的 UID 或账号，用逗号分隔");
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
        body.add("visibility_user_ids", tokens(text(composerBinding.visibilityUsersInput)));
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
        composerBinding.visibilityUsersInput.setEnabled(enabled);
        composerBinding.contentInput.setEnabled(enabled);
    }

    private void showMomentMenu(JsonObject item) {
        if (!isUiActive() || item == null) return;
        JsonObject snapshot = item.deepCopy();
        List<String> labels = new ArrayList<>();
        if (flag(snapshot, "can_pin")) labels.add(flag(snapshot, "is_pinned") ? "取消置顶" : "置顶动态");
        if (flag(snapshot, "can_edit")) labels.add("编辑动态");
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
                    else if ("编辑动态".equals(action)) showComposer(snapshot);
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
        BottomSheetDialog dialog = new BottomSheetDialog(this);
        SheetMomentCommentsBinding sheetBinding = SheetMomentCommentsBinding.inflate(getLayoutInflater());
        commentsDialog = dialog;
        commentsBinding = sheetBinding;
        dialog.setContentView(sheetBinding.getRoot());
        commentsBinding.sendButton.setOnClickListener(view -> submitComment());
        commentsBinding.commentInput.setOnEditorActionListener((view, actionId, event) -> {
            if (actionId != EditorInfo.IME_ACTION_SEND) return false;
            submitComment();
            return true;
        });
        commentsBinding.replyHint.setOnClickListener(view -> clearReply());
        dialog.setOnDismissListener(ignored -> {
            if (commentsDialog != dialog) return;
            if (commentRequest != null) commentRequest.cancel();
            commentRequest = null;
            commentsBinding = null;
            commentsDialog = null;
            commentsMoment = null;
            replyingCommentId = 0L;
        });
        GlassBottomSheet.prepare(dialog, this, 0.90f, false);
        dialog.show();
        loadComments();
    }

    private void loadComments() {
        if (commentsBinding == null || commentsMoment == null) return;
        if (commentRequest != null) commentRequest.cancel();
        commentsBinding.progress.setVisibility(View.VISIBLE);
        commentsBinding.commentsContainer.removeAllViews();
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
                renderCommentsError(result.message().isEmpty() ? "评论加载失败" : result.message());
                return;
            }
            List<JsonObject> comments = result.objectItems();
            commentsMoment.addProperty("comment_count", comments.size());
            adapter.notifyById(momentId);
            commentsBinding.titleText.setText("评论 " + comments.size());
            if (comments.isEmpty()) renderCommentsError("还没有评论，来说两句吧");
            else for (JsonObject comment : comments) addCommentView(comment);
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
    }

    private void addCommentView(JsonObject comment) {
        if (commentsBinding == null) return;
        MaterialCardView card = new MaterialCardView(this);
        card.setRadius(dp(8));
        card.setCardElevation(0f);
        LinearLayout body = new LinearLayout(this);
        body.setOrientation(LinearLayout.VERTICAL);
        body.setPadding(dp(12), dp(10), dp(12), dp(10));
        String author = Jsons.string(comment, "display_name");
        String parent = Jsons.string(comment, "parent_display_name");
        TextView name = new TextView(this);
        name.setText(parent.isEmpty()
            ? author
            : author + " " + RuntimeLanguage.translate(this, "回复") + " " + parent);
        name.setTextSize(13f);
        TextView content = new TextView(this);
        RuntimeLanguage.setDynamicText(content, Jsons.string(comment, "content"));
        content.setTextSize(15f);
        content.setPadding(0, dp(4), 0, 0);
        body.addView(name, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));
        body.addView(content, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));
        card.addView(body);
        LinearLayout.LayoutParams params = new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT);
        params.setMargins(0, 0, 0, dp(7));
        commentsBinding.commentsContainer.addView(card, params);
        card.setOnClickListener(view -> {
            replyingCommentId = Jsons.longValue(comment, "id");
            RuntimeLanguage.setDynamicText(commentsBinding.replyHint,
                RuntimeLanguage.translate(this, "正在回复 ") + author
                    + RuntimeLanguage.translate(this, " · 点击取消"));
            commentsBinding.replyHint.setVisibility(View.VISIBLE);
            commentsBinding.commentInput.requestFocus();
        });
        card.setOnLongClickListener(view -> {
            if (!flag(comment, "can_delete")) return false;
            confirmDeleteComment(comment);
            return true;
        });
    }

    private void clearReply() {
        replyingCommentId = 0L;
        if (commentsBinding != null) commentsBinding.replyHint.setVisibility(View.GONE);
    }

    private void submitComment() {
        if (commentsBinding == null || commentsMoment == null || commentRequest != null) return;
        String content = text(commentsBinding.commentInput);
        if (content.isEmpty()) { message("请输入评论内容"); return; }
        JsonObject body = new JsonObject();
        body.addProperty("content", content);
        if (replyingCommentId > 0) body.addProperty("parent_id", replyingCommentId);
        commentsBinding.sendButton.setEnabled(false);
        long momentId = Jsons.longValue(commentsMoment, "id");
        commentRequest = AppAccess.from(this).repository().post("/api/user/moments/" + momentId + "/comments", body, result -> {
            commentRequest = null;
            if (commentsBinding == null || isFinishing() || isDestroyed()) return;
            commentsBinding.sendButton.setEnabled(true);
            if (result.isAuthenticationFailure()) { login(); return; }
            if (!result.isSuccessful()) { message(result.message().isEmpty() ? "评论失败" : result.message()); return; }
            commentsBinding.commentInput.setText("");
            clearReply();
            loadComments();
        });
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
            .setItems(new String[]{"发送给好友", "发送到群聊或聊天室", "发送给在线客服", "系统分享"},
                (dialog, which) -> {
                    if (which == 0) {
                        pendingForwardMoment = item;
                        forwardFriendPicker.launch(SocialDirectoryActivity.pickFriendsIntent(
                            this, 1, "选择接收动态的好友", new long[0], "该好友不可选择"));
                    } else if (which == 1) {
                        pendingForwardMoment = item;
                        forwardRoomPicker.launch(SocialDirectoryActivity.pickRoomsIntent(
                            this, 1, "选择群聊或聊天室", new long[0]));
                    } else if (which == 2) {
                        sendMomentForward(item, "service", 0, false);
                    } else {
                        sendMomentForward(item, "external", 0, true);
                    }
                })
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

    private static ArrayList<JsonObject> copyMoments(List<JsonObject> values) {
        ArrayList<JsonObject> copied = new ArrayList<>();
        if (values == null) return copied;
        for (JsonObject value : values) {
            if (value != null) copied.add(value.deepCopy());
        }
        return copied;
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
        if (likesRequest != null) likesRequest.cancel();
        BottomSheetDialog composer = composerDialog;
        BottomSheetDialog comments = commentsDialog;
        BottomSheetDialog likes = likesDialog;
        composerDialog = null;
        commentsDialog = null;
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
            next.sort(MomentTimelineActivity::compareMoments);
            if (binding != null && binding.recycler.isComputingLayout()) {
                binding.recycler.post(() -> {
                    if (isUiActive()) submit(next);
                });
            } else {
                submit(next);
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
                row.pinBadge.setVisibility(flag(item, "is_pinned") ? View.VISIBLE : View.GONE);
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
                boolean profileTimeline = targetUserId > 0;
                row.avatar.setVisibility(profileTimeline ? View.GONE : View.VISIBLE);
                row.authorArea.setVisibility(profileTimeline ? View.GONE : View.VISIBLE);
                row.avatar.setOnClickListener(profile);
                row.authorArea.setOnClickListener(profile);
                boolean manageable = flag(item, "can_pin") || flag(item, "can_edit") || flag(item, "can_delete");
                row.moreButton.setVisibility(manageable ? View.VISIBLE : View.GONE);
                row.moreButton.setOnClickListener(view -> showMomentMenu(item.deepCopy()));
                List<JsonObject> media = new ArrayList<>();
                for (JsonElement value : Jsons.array(item, "attachments")) if (value.isJsonObject()) media.add(value.getAsJsonObject());
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
                row.momentLikeButton.setText((flag(item, "is_liked") ? "已赞" : "点赞") + (likeCount > 0 ? " " + likeCount : ""));
                row.momentCommentButton.setText("评论" + (commentCount > 0 ? " " + commentCount : ""));
                row.momentFavoriteButton.setText((flag(item, "is_favorited") ? "已收藏" : "收藏") + (favoriteCount > 0 ? " " + favoriteCount : ""));
                row.momentForwardButton.setText("转发" + (forwardCount > 0 ? " " + forwardCount : ""));
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
