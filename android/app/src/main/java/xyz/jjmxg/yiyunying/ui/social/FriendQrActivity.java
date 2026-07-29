package xyz.jjmxg.yiyunying.ui.social;

import android.app.Activity;
import android.content.ClipData;
import android.content.ClipboardManager;
import android.content.Context;
import android.content.Intent;
import android.graphics.Bitmap;
import android.graphics.BitmapFactory;
import android.net.Uri;
import android.os.Bundle;
import android.view.View;
import android.widget.EditText;

import androidx.activity.result.ActivityResultLauncher;
import androidx.activity.result.contract.ActivityResultContracts;
import androidx.annotation.NonNull;

import xyz.jjmxg.yiyunying.ui.common.YiyunyingDialogBuilder;
import com.google.android.material.snackbar.Snackbar;
import com.google.gson.JsonArray;
import com.google.gson.JsonObject;
import com.google.zxing.BarcodeFormat;
import com.google.zxing.BinaryBitmap;
import com.google.zxing.DecodeHintType;
import com.google.zxing.LuminanceSource;
import com.google.zxing.MultiFormatReader;
import com.google.zxing.RGBLuminanceSource;
import com.google.zxing.Result;
import com.google.zxing.common.HybridBinarizer;
import com.journeyapps.barcodescanner.BarcodeEncoder;
import com.journeyapps.barcodescanner.ScanContract;
import com.journeyapps.barcodescanner.ScanOptions;

import java.io.InputStream;
import java.util.Collections;
import java.util.EnumMap;
import java.util.LinkedHashMap;
import java.util.Locale;
import java.util.Map;
import java.util.ArrayList;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

import xyz.jjmxg.yiyunying.core.AppAccess;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.data.api.RequestHandle;
import xyz.jjmxg.yiyunying.databinding.ActivityFriendQrBinding;
import xyz.jjmxg.yiyunying.domain.Role;
import xyz.jjmxg.yiyunying.ui.auth.LoginActivity;
import xyz.jjmxg.yiyunying.ui.chat.ChatActivity;
import xyz.jjmxg.yiyunying.ui.common.RecordDetailDialog;
import xyz.jjmxg.yiyunying.ui.upload.MediaPickerActivity;

public final class FriendQrActivity extends xyz.jjmxg.yiyunying.ui.common.SystemInsetActivity {
    private static final String EXTRA_SCAN_NOW = "scan_now";
    private static final String STATE_SCAN_IN_PROGRESS = "scan_in_progress";
    private static final int QR_DECODE_MAX_SIDE = 2048;

    private ActivityFriendQrBinding binding;
    private RequestHandle request;
    private RequestHandle groupRequest;
    private String uid = "";
    private String qrPayload = "";
    private boolean scanInProgress;
    private final ExecutorService qrDecoder = Executors.newSingleThreadExecutor();

    private final ActivityResultLauncher<ScanOptions> scanner = registerForActivityResult(
        new ScanContract(), result -> {
            scanInProgress = false;
            updateActions();
            String contents = result.getContents();
            if (contents != null && !contents.trim().isEmpty()) {
                routeScannedPayload(contents.trim());
            }
        });

    private final ActivityResultLauncher<Intent> galleryPicker = registerForActivityResult(
        new ActivityResultContracts.StartActivityForResult(), result -> {
            if (result.getResultCode() != RESULT_OK || result.getData() == null) return;
            ArrayList<Uri> selected = result.getData()
                .getParcelableArrayListExtra(MediaPickerActivity.EXTRA_SELECTED_URIS);
            if (selected != null && !selected.isEmpty()) decodeSelectedImage(selected.get(0));
        });

    public static void open(Context context, boolean scanNow) {
        context.startActivity(intent(context, scanNow));
    }

    public static Intent intent(Context context, boolean scanNow) {
        Intent intent = new Intent(context, FriendQrActivity.class)
            .putExtra(EXTRA_SCAN_NOW, scanNow);
        if (!(context instanceof Activity)) intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
        return intent;
    }

    @Override protected void onCreate(Bundle state) {
        super.onCreate(state);
        if (!AppAccess.from(this).session().isAuthenticated()
            || AppAccess.from(this).session().role() != Role.USER) {
            startActivity(new Intent(this, LoginActivity.class)
                .putExtra(LoginActivity.EXTRA_FORCE_LOGIN, true)
                .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TASK));
            finish();
            return;
        }
        binding = ActivityFriendQrBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());
        scanInProgress = state != null && state.getBoolean(STATE_SCAN_IN_PROGRESS, false);
        // The external scanner cannot survive process recreation as an active request.
        // Always let the user explicitly resume it instead of trapping this page in a loop.
        if (state != null) scanInProgress = false;
        binding.toolbar.setNavigationOnClickListener(view -> finish());
        binding.scanButton.setOnClickListener(view -> scan());
        binding.galleryButton.setOnClickListener(view -> galleryPicker.launch(
            MediaPickerActivity.imageIntent(this, 1)));
        binding.copyButton.setOnClickListener(view -> copyUid());
        binding.shareButton.setOnClickListener(view -> share());
        updateActions();
        loadQrCode();
        if (state == null && getIntent().getBooleanExtra(EXTRA_SCAN_NOW, false)) {
            getIntent().removeExtra(EXTRA_SCAN_NOW);
            binding.scanButton.post(this::scan);
        }
    }

    @Override protected void onSaveInstanceState(@NonNull Bundle outState) {
        outState.putBoolean(STATE_SCAN_IN_PROGRESS, scanInProgress);
        super.onSaveInstanceState(outState);
    }

    private void loadQrCode() {
        binding.progress.setVisibility(View.VISIBLE);
        request = AppAccess.from(this).repository().get("/api/user/friends/qr-code", new LinkedHashMap<>(), result -> {
            request = null;
            if (binding == null) return;
            binding.progress.setVisibility(View.INVISIBLE);
            if (!result.isSuccessful()) {
                message(result.message().isEmpty() ? "好友码加载失败" : result.message());
                return;
            }
            JsonObject data = result.dataObject();
            uid = Jsons.string(data, "uid");
            qrPayload = Jsons.string(data, "qr_payload");
            binding.uidText.setText("UID：" + uid);
            try {
                binding.qrImage.setImageBitmap(new BarcodeEncoder().encodeBitmap(
                    qrPayload, BarcodeFormat.QR_CODE, 900, 900));
            } catch (Exception exception) {
                message("好友二维码生成失败，请点击重试");
            }
        });
    }

    private void scan() {
        if (scanInProgress || binding == null) return;
        scanInProgress = true;
        updateActions();
        ScanOptions options = new ScanOptions();
        options.setDesiredBarcodeFormats(ScanOptions.QR_CODE);
        options.setPrompt("将易运盈好友码或群二维码放入取景框");
        options.setBeepEnabled(false);
        options.setOrientationLocked(true);
        options.setCaptureActivity(PortraitQrCaptureActivity.class);
        scanner.launch(options);
    }

    private void decodeSelectedImage(Uri uri) {
        if (uri == null || binding == null) return;
        binding.progress.setVisibility(View.VISIBLE);
        updateActions(false);
        qrDecoder.execute(() -> {
            String payload = null;
            try {
                payload = decodeQr(uri);
            } catch (Exception ignored) { }
            String decoded = payload;
            runOnUiThread(() -> {
                if (binding == null) return;
                binding.progress.setVisibility(View.INVISIBLE);
                updateActions();
                if (decoded == null || decoded.trim().isEmpty()) {
                    message("未识别到有效的易运盈二维码，请选择清晰的原图");
                    return;
                }
                routeScannedPayload(decoded.trim());
            });
        });
    }

    private void routeScannedPayload(String payload) {
        if (payload == null || payload.trim().isEmpty()) {
            message("二维码内容为空");
            return;
        }
        String normalized = payload.trim();
        String lower = normalized.toLowerCase(Locale.ROOT);
        if (lower.startsWith("yyygroup:") || lower.startsWith("yiyunying://group/")) {
            previewGroup(normalized);
            return;
        }
        if (lower.startsWith("yyyfriend:")) {
            confirmFriendRequest(normalized);
            return;
        }
        message("该二维码不是易运盈好友码或群二维码");
    }

    private void previewGroup(String payload) {
        if (groupRequest != null || binding == null) return;
        JsonObject body = new JsonObject();
        body.addProperty("qr_code", payload);
        binding.progress.setVisibility(View.VISIBLE);
        updateActions(false);
        groupRequest = AppAccess.from(this).repository().post("/api/user/chat-rooms/scan-qr", body, result -> {
            groupRequest = null;
            if (binding == null) return;
            binding.progress.setVisibility(View.INVISIBLE);
            updateActions();
            if (!result.isSuccessful()) {
                message(result.message().isEmpty() ? "群二维码识别失败" : result.message());
                return;
            }
            JsonObject room = Jsons.object(result.dataObject(), "room");
            long roomId = Jsons.longValue(room, "id");
            String roomName = Jsons.string(room, "name");
            String action = Jsons.string(room, "join_action");
            String actionLabel = Jsons.string(room, "join_label");
            JsonObject visible = visibleGroup(room);
            if ("enter".equals(action)) {
                RecordDetailDialog.show(this, "群聊资料", visible, "进入群聊",
                    () -> ChatActivity.openRoom(this, roomId, roomName));
            } else if ("join".equals(action) || "apply".equals(action)) {
                RecordDetailDialog.show(this, "群聊资料", visible,
                    actionLabel.isEmpty() ? "加入群聊" : actionLabel,
                    () -> joinGroup(payload));
            } else {
                RecordDetailDialog.show(this, "群聊资料", visible);
            }
        });
    }

    private JsonObject visibleGroup(JsonObject room) {
        JsonObject visible = new JsonObject();
        visible.addProperty("name", Jsons.string(room, "name"));
        visible.addProperty("group_number", Jsons.string(room, "group_number"));
        long members = Jsons.longValue(room, "member_count");
        long maximum = Jsons.longValue(room, "max_members");
        visible.addProperty("members", maximum > 0 ? members + " / " + maximum : String.valueOf(members));
        visible.addProperty("join_mode_text", Jsons.string(room, "join_mode_text"));
        String description = Jsons.string(room, "description");
        if (!description.isEmpty()) visible.addProperty("description", description);
        String announcement = Jsons.string(room, "announcement");
        if (!announcement.isEmpty()) visible.addProperty("announcement", announcement);
        JsonArray tags = Jsons.array(room, "tags");
        if (!tags.isEmpty()) visible.add("tags", tags.deepCopy());
        String createdAt = Jsons.string(room, "created_at");
        if (!createdAt.isEmpty()) visible.addProperty("created_at", createdAt);
        visible.addProperty("status_text", Jsons.string(room, "join_label"));
        return visible;
    }

    private void joinGroup(String payload) {
        if (groupRequest != null || binding == null) return;
        JsonObject body = new JsonObject();
        body.addProperty("qr_code", payload);
        body.addProperty("message", "通过群二维码申请加入");
        binding.progress.setVisibility(View.VISIBLE);
        updateActions(false);
        groupRequest = AppAccess.from(this).repository().post("/api/user/chat-rooms/scan-qr/join", body, result -> {
            groupRequest = null;
            if (binding == null) return;
            binding.progress.setVisibility(View.INVISIBLE);
            updateActions();
            if (!result.isSuccessful()) {
                message(result.message().isEmpty() ? "加入群聊失败" : result.message());
                return;
            }
            JsonObject data = result.dataObject();
            JsonObject room = Jsons.object(data, "room");
            if (bool(data, "joined")) {
                message(result.message().isEmpty() ? "已加入群聊" : result.message());
                ChatActivity.openRoom(this, Jsons.longValue(room, "id"), Jsons.string(room, "name"));
                return;
            }
            message(result.message().isEmpty() ? "入群申请已提交" : result.message());
        });
    }

    private static boolean bool(JsonObject object, String key) {
        if (object == null || !object.has(key) || object.get(key).isJsonNull()) return false;
        try {
            return object.get(key).getAsBoolean();
        } catch (RuntimeException ignored) {
            return "1".equals(object.get(key).getAsString());
        }
    }
    private String decodeQr(Uri uri) throws Exception {
        BitmapFactory.Options bounds = new BitmapFactory.Options();
        bounds.inJustDecodeBounds = true;
        try (InputStream stream = getContentResolver().openInputStream(uri)) {
            BitmapFactory.decodeStream(stream, null, bounds);
        }
        if (bounds.outWidth <= 0 || bounds.outHeight <= 0) return null;
        int sample = 1;
        while (Math.max(bounds.outWidth, bounds.outHeight) / sample > QR_DECODE_MAX_SIDE) sample *= 2;
        BitmapFactory.Options options = new BitmapFactory.Options();
        options.inSampleSize = Math.max(1, sample);
        options.inPreferredConfig = Bitmap.Config.ARGB_8888;
        Bitmap bitmap;
        try (InputStream stream = getContentResolver().openInputStream(uri)) {
            bitmap = BitmapFactory.decodeStream(stream, null, options);
        }
        if (bitmap == null) return null;
        try {
            int width = bitmap.getWidth();
            int height = bitmap.getHeight();
            int[] pixels = new int[width * height];
            bitmap.getPixels(pixels, 0, width, 0, 0, width, height);
            RGBLuminanceSource source = new RGBLuminanceSource(width, height, pixels);
            String result = decodeSource(source);
            return result == null ? decodeSource(source.invert()) : result;
        } finally {
            bitmap.recycle();
        }
    }

    private static String decodeSource(LuminanceSource source) {
        MultiFormatReader reader = new MultiFormatReader();
        Map<DecodeHintType, Object> hints = new EnumMap<>(DecodeHintType.class);
        hints.put(DecodeHintType.POSSIBLE_FORMATS, Collections.singletonList(BarcodeFormat.QR_CODE));
        hints.put(DecodeHintType.TRY_HARDER, Boolean.TRUE);
        try {
            Result result = reader.decode(new BinaryBitmap(new HybridBinarizer(source)), hints);
            return result == null ? null : result.getText();
        } catch (Exception ignored) {
            return null;
        } finally {
            reader.reset();
        }
    }

    private void updateActions() {
        updateActions(!scanInProgress);
    }

    private void updateActions(boolean enabled) {
        if (binding == null) return;
        binding.scanButton.setEnabled(enabled);
        binding.galleryButton.setEnabled(enabled);
    }

    private void confirmFriendRequest(String payload) {
        if (isFinishing() || isDestroyed()) return;
        EditText input = new EditText(this);
        input.setHint("好友验证信息");
        input.setText("你好，我想添加你为好友");
        input.setSelection(input.length());
        int padding = Math.round(22 * getResources().getDisplayMetrics().density);
        input.setPadding(padding, padding / 2, padding, padding / 2);
        new YiyunyingDialogBuilder(this)
            .setTitle("发送好友申请")
            .setMessage("识别成功。发送后仍需对方同意。")
            .setView(input)
            .setPositiveButton("发送申请", (dialog, which) -> submit(payload, input.getText().toString().trim()))
            .setNegativeButton("取消", null)
            .show();
    }

    private void submit(String payload, String verification) {
        if (request != null) return;
        JsonObject body = new JsonObject();
        body.addProperty("qr_payload", payload);
        body.addProperty("message", verification);
        binding.progress.setVisibility(View.VISIBLE);
        request = AppAccess.from(this).repository().post("/api/user/friends/scan-qr", body, result -> {
            request = null;
            if (binding == null) return;
            binding.progress.setVisibility(View.INVISIBLE);
            message(result.isSuccessful()
                ? (result.message().isEmpty() ? "好友申请已发送" : result.message())
                : (result.message().isEmpty() ? "好友申请发送失败" : result.message()));
        });
    }

    private void copyUid() {
        if (uid.isEmpty()) { message("UID 尚未加载"); return; }
        ClipboardManager clipboard = (ClipboardManager) getSystemService(Context.CLIPBOARD_SERVICE);
        clipboard.setPrimaryClip(ClipData.newPlainText("易运盈 UID", uid));
        message("UID 已复制");
    }

    private void share() {
        if (qrPayload.isEmpty()) { message("好友码尚未加载"); return; }
        Intent intent = new Intent(Intent.ACTION_SEND);
        intent.setType("text/plain");
        intent.putExtra(Intent.EXTRA_SUBJECT, "我的易运盈好友码");
        intent.putExtra(Intent.EXTRA_TEXT, "我的 UID：" + uid + "\n好友码：" + qrPayload);
        startActivity(Intent.createChooser(intent, "分享好友码"));
    }

    private void message(String value) {
        if (binding != null) Snackbar.make(binding.getRoot(), value, Snackbar.LENGTH_LONG).show();
    }

    @Override protected void onDestroy() {
        if (request != null) request.cancel();
        if (groupRequest != null) groupRequest.cancel();
        qrDecoder.shutdownNow();
        binding = null;
        super.onDestroy();
    }
}
