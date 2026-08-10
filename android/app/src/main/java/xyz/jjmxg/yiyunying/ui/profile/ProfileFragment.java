package xyz.jjmxg.yiyunying.ui.profile;

import android.content.Intent;
import android.os.Bundle;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.net.Uri;
import android.database.Cursor;
import android.provider.OpenableColumns;

import androidx.annotation.NonNull;
import androidx.annotation.Nullable;
import androidx.activity.result.ActivityResultLauncher;
import androidx.activity.result.ActivityResult;
import androidx.activity.result.contract.ActivityResultContracts;

import com.google.gson.JsonObject;
import com.google.gson.JsonArray;
import com.google.gson.JsonElement;
import xyz.jjmxg.yiyunying.ui.common.YiyunyingDialogBuilder;
import com.google.android.material.card.MaterialCardView;
import android.widget.GridLayout;
import android.widget.ImageView;
import android.widget.ScrollView;

import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.Locale;
import java.util.Map;

import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.databinding.FragmentProfileBinding;
import xyz.jjmxg.yiyunying.domain.Role;
import xyz.jjmxg.yiyunying.domain.module.ActionSpec;
import xyz.jjmxg.yiyunying.domain.module.FieldSpec;
import xyz.jjmxg.yiyunying.domain.module.FieldType;
import xyz.jjmxg.yiyunying.ui.common.BaseFragment;
import xyz.jjmxg.yiyunying.ui.common.DynamicFormDialog;
import xyz.jjmxg.yiyunying.ui.common.ImageLoader;
import xyz.jjmxg.yiyunying.ui.upload.ContentUriRequestBody;
import xyz.jjmxg.yiyunying.ui.upload.ImageGalleryActivity;
import xyz.jjmxg.yiyunying.ui.upload.MediaPickerActivity;
import xyz.jjmxg.yiyunying.ui.upload.UploadPolicyStore;
import xyz.jjmxg.yiyunying.data.api.RequestHandle;
import xyz.jjmxg.yiyunying.ui.settings.UserSettingsActivity;

public final class ProfileFragment extends BaseFragment {
    private FragmentProfileBinding binding;
    private JsonObject profile = new JsonObject();
    private String avatarUrl = "";
    private RequestHandle avatarUpload;
    private RequestHandle avatarHistoryRequest;
    private int profileLoadGeneration;
    private boolean profileRendered;
    private boolean readOnlyAdmin;
    private boolean profileEditable = true;
    private final ActivityResultLauncher<Intent> avatarPicker = registerForActivityResult(
        new ActivityResultContracts.StartActivityForResult(), this::uploadAvatar);

    public static ProfileFragment newInstance() { return new ProfileFragment(); }

    @Nullable
    @Override public View onCreateView(@NonNull LayoutInflater inflater, @Nullable ViewGroup container, @Nullable Bundle state) {
        binding = FragmentProfileBinding.inflate(inflater, container, false);
        host().setPageTitle("个人资料");
        boolean user = app().session().role() == Role.USER;
        readOnlyAdmin = app().session().isAdminBillingOnly();
        binding.qqLayout.setVisibility(user ? View.VISIBLE : View.GONE);
        binding.signatureLayout.setVisibility(user ? View.VISIBLE : View.GONE);
        binding.publicProfileSwitch.setVisibility(user ? View.VISIBLE : View.GONE);
        binding.saveButton.setVisibility(readOnlyAdmin ? View.GONE : View.VISIBLE);
        binding.changePasswordButton.setVisibility(readOnlyAdmin ? View.GONE : View.VISIBLE);
        binding.dynamicPrivacyButton.setVisibility(user ? View.VISIBLE : View.GONE);
        binding.nicknameInput.setEnabled(!readOnlyAdmin);
        binding.emailInput.setEnabled(!readOnlyAdmin);
        binding.phoneInput.setEnabled(!readOnlyAdmin);
        binding.avatarButton.setVisibility(readOnlyAdmin ? View.GONE : View.VISIBLE);
        binding.avatarHistoryButton.setVisibility(user ? View.VISIBLE : View.GONE);
        binding.avatarButton.setOnClickListener(view ->
            avatarPicker.launch(MediaPickerActivity.imageIntent(requireContext(), 1)));
        binding.avatarHistoryButton.setOnClickListener(view -> loadAvatarHistory());
        binding.avatar.setOnClickListener(view -> previewAvatar());
        binding.saveButton.setOnClickListener(view -> save());
        binding.changePasswordButton.setOnClickListener(view -> changePassword());
        binding.dynamicPrivacyButton.setOnClickListener(view -> UserSettingsActivity.openDynamicPrivacy(requireContext()));
        binding.logoutButton.setOnClickListener(view -> host().onLogoutRequested());
        loadProfilePolicy();
        load();
        return binding.getRoot();
    }

    private void loadProfilePolicy() {
        if (app().session().role() != Role.USER) {
            applyEditingPolicy();
            return;
        }
        track(app().repository().getPublic("/api/public/bootstrap", new LinkedHashMap<>(), result -> {
            if (binding == null || !result.isSuccessful()) return;
            JsonObject settings = Jsons.object(result.dataObject(), "settings");
            profileEditable = !settings.has("profile_edit_enabled")
                || settings.get("profile_edit_enabled").getAsBoolean();
            applyEditingPolicy();
            if (profileRendered) render();
        }));
    }

    private void applyEditingPolicy() {
        if (binding == null) return;
        boolean editable = !readOnlyAdmin && profileEditable;
        binding.nicknameInput.setEnabled(editable);
        binding.emailInput.setEnabled(editable);
        binding.phoneInput.setEnabled(editable);
        binding.qqInput.setEnabled(editable);
        binding.signatureInput.setEnabled(editable);
        binding.publicProfileSwitch.setEnabled(editable);
        binding.saveButton.setVisibility(editable ? View.VISIBLE : View.GONE);
        binding.changePasswordButton.setVisibility(readOnlyAdmin ? View.GONE : View.VISIBLE);
        binding.avatarButton.setVisibility(editable ? View.VISIBLE : View.GONE);
    }

    private void load() {
        int generation = ++profileLoadGeneration;
        if (!profileRendered) binding.progress.setVisibility(View.VISIBLE);
        String path = app().session().role().mePath();
        LinkedHashMap<String, String> query = new LinkedHashMap<>();
        track(app().repository().getCached(path, query, cached -> {
            if (binding == null || generation != profileLoadGeneration || !cached.isSuccessful()) return;
            JsonObject cachedProfile = Jsons.object(cached.dataObject(), app().session().role().wireName());
            if (cachedProfile.size() == 0) return;
            applyProfile(cachedProfile);
            binding.progress.setVisibility(View.GONE);
        }));
        track(app().repository().get(path, query, result -> {
            if (binding == null || generation != profileLoadGeneration) return;
            binding.progress.setVisibility(View.GONE);
            if (!result.isSuccessful()) {
                if (!profileRendered) handleFailure(result, binding.getRoot());
                return;
            }
            applyProfile(Jsons.object(result.dataObject(), app().session().role().wireName()));
        }));
    }

    private void applyProfile(JsonObject next) {
        if (next == null || (profileRendered && profile.equals(next))) return;
        profile = next.deepCopy();
        profileRendered = true;
        render();
    }

    private void render() {
        String account = Jsons.string(profile, "account");
        binding.accountText.setText(account);
        avatarUrl = Jsons.string(profile, "avatar");
        ImageLoader.get().load(ImageLoader.get().absoluteUrl(requireContext(), avatarUrl), binding.avatar, xyz.jjmxg.yiyunying.R.drawable.ic_person);
        binding.nicknameInput.setText(Jsons.string(profile, "nickname"));
        binding.emailInput.setText(Jsons.string(profile, "email"));
        binding.phoneInput.setText(Jsons.string(profile, "phone"));
        if (app().session().role() == Role.USER) {
            binding.qqInput.setText(Jsons.string(profile, "qq"));
            binding.signatureInput.setText(Jsons.string(profile, "signature"));
            binding.publicProfileSwitch.setChecked(profile.has("public_profile") && profile.get("public_profile").getAsBoolean());
            binding.statusText.setText("等级 " + Jsons.string(profile, "level_code") + " · 余额 "
                + Jsons.string(profile, "balance") + (profileEditable ? "" : " · 资料修改已关闭"));
        } else if (app().session().role() == Role.ADMIN) {
            String restriction = app().session().isAdminBillingOnly() ? " · " + app().session().adminAccessReason() : "";
            binding.statusText.setText("会员 " + Jsons.string(profile, "membership_level") + " · 余额 " + Jsons.string(profile, "balance") + " · 到期 " + Jsons.string(profile, "membership_expired_at") + restriction);
        } else {
            binding.statusText.setText("平台等级 " + Jsons.string(profile, "level") + " · 余额 " + Jsons.string(profile, "balance") + " · " + Jsons.string(profile, "platform_key"));
        }
    }

    private void save() {
        JsonObject body = new JsonObject();
        body.addProperty("nickname", text(binding.nicknameInput.getText()));
        body.addProperty("email", text(binding.emailInput.getText()));
        body.addProperty("phone", text(binding.phoneInput.getText()));
        body.addProperty("avatar", avatarUrl);
        if (app().session().role() == Role.USER) {
            body.addProperty("qq", text(binding.qqInput.getText()));
            body.addProperty("signature", text(binding.signatureInput.getText()));
            body.addProperty("public_profile", binding.publicProfileSwitch.isChecked());
        }
        binding.progress.setVisibility(View.VISIBLE);
        track(app().repository().put("/api/" + app().session().role().wireName() + "/profile", body, result -> {
            if (binding == null) return;
            binding.progress.setVisibility(View.GONE);
            if (handleFailure(result, binding.getRoot())) return;
            message(binding.getRoot(), result.message().isEmpty() ? "资料已保存" : result.message());
            host().refreshProfileChrome();
            load();
        }));
    }

    private void changePassword() {
        ActionSpec action = ActionSpec.builder("修改密码", "PUT", "/api/" + app().session().role().wireName() + "/password")
            .fields(
                FieldSpec.typed("old_password", "原密码", FieldType.PASSWORD, true),
                FieldSpec.typed("new_password", "新密码", FieldType.PASSWORD, true)
            ).build();
        DynamicFormDialog.show(requireContext(), action, null, body -> {
            binding.progress.setVisibility(View.VISIBLE);
            track(app().repository().put(action.pathTemplate(), body, result -> {
                if (binding == null) return;
                binding.progress.setVisibility(View.GONE);
                if (handleFailure(result, binding.getRoot())) return;
                app().session().clearAuthentication();
                host().onAuthenticationExpired();
            }));
        });
    }

    private void uploadAvatar(ActivityResult pickerResult) {
        if (pickerResult.getResultCode() != android.app.Activity.RESULT_OK || pickerResult.getData() == null) return;
        ArrayList<Uri> uris = pickerResult.getData()
            .getParcelableArrayListExtra(MediaPickerActivity.EXTRA_SELECTED_URIS);
        Uri uri = uris == null || uris.isEmpty() ? null : uris.get(0);
        if (uri == null || avatarUpload != null || binding == null) return;
        String name = "头像.jpg";
        long size = -1;
        try (Cursor cursor = requireContext().getContentResolver().query(uri, null, null, null, null)) {
            if (cursor != null && cursor.moveToFirst()) {
                int nameIndex = cursor.getColumnIndex(OpenableColumns.DISPLAY_NAME);
                int sizeIndex = cursor.getColumnIndex(OpenableColumns.SIZE);
                if (nameIndex >= 0 && !cursor.isNull(nameIndex)) name = cursor.getString(nameIndex);
                if (sizeIndex >= 0 && !cursor.isNull(sizeIndex)) size = cursor.getLong(sizeIndex);
            }
        }
        String mime = requireContext().getContentResolver().getType(uri);
        if (mime == null) mime = "image/jpeg";
        if (!UploadPolicyStore.accepts(requireContext(), "image", size)) {
            message(binding.getRoot(), UploadPolicyStore.rejectionMessage(requireContext(), "image", size));
            return;
        }
        ContentUriRequestBody body = new ContentUriRequestBody(requireContext().getContentResolver(), uri, mime, size);
        binding.progress.setVisibility(View.VISIBLE);
        binding.avatarButton.setEnabled(false);
        avatarUpload = app().repository().upload("/api/" + app().session().role().wireName() + "/profile/avatar",
            name, mime, body, new LinkedHashMap<>(), result -> {
                avatarUpload = null;
                if (binding == null) return;
                binding.progress.setVisibility(View.GONE);
                binding.avatarButton.setEnabled(true);
                if (handleFailure(result, binding.getRoot())) return;
                ImageLoader.get().invalidate(ImageLoader.get().absoluteUrl(requireContext(), avatarUrl));
                avatarUrl = Jsons.string(result.dataObject(), "avatar");
                ImageLoader.get().invalidate(ImageLoader.get().absoluteUrl(requireContext(), avatarUrl));
                ImageLoader.get().load(ImageLoader.get().absoluteUrl(requireContext(), avatarUrl), binding.avatar, xyz.jjmxg.yiyunying.R.drawable.ic_person);
                host().refreshProfileChrome();
                message(binding.getRoot(), "头像上传成功");
            });
        track(avatarUpload);
    }

    private void previewAvatar() {
        if (avatarUrl.isEmpty()) return;
        JsonObject image = new JsonObject(); image.addProperty("url", avatarUrl); image.addProperty("file_name", "我的头像");
        ImageGalleryActivity.open(requireContext(), java.util.Collections.singletonList(image), 0);
    }

    private void loadAvatarHistory() {
        if (avatarHistoryRequest != null) return;
        binding.progress.setVisibility(View.VISIBLE);
        avatarHistoryRequest = app().repository().get("/api/user/profile/avatar-history", new LinkedHashMap<>(), result -> {
            avatarHistoryRequest = null;
            if (binding == null) return;
            binding.progress.setVisibility(View.GONE);
            if (handleFailure(result, binding.getRoot())) return;
            showAvatarHistory(Jsons.array(result.dataObject(), "items"));
        });
        track(avatarHistoryRequest);
    }

    private void showAvatarHistory(JsonArray items) {
        ScrollView scroll = new ScrollView(requireContext());
        GridLayout grid = new GridLayout(requireContext());
        grid.setColumnCount(3);
        grid.setPadding(dp(10), dp(10), dp(10), dp(10));
        scroll.addView(grid);
        for (JsonElement element : items) {
            if (!element.isJsonObject()) continue;
            JsonObject history = element.getAsJsonObject();
            MaterialCardView card = new MaterialCardView(requireContext());
            GridLayout.LayoutParams params = new GridLayout.LayoutParams();
            params.width = dp(92); params.height = dp(108); params.setMargins(dp(5), dp(5), dp(5), dp(5));
            card.setLayoutParams(params); card.setRadius(dp(8)); card.setCardElevation(0);
            ImageView image = new ImageView(requireContext());
            image.setScaleType(ImageView.ScaleType.CENTER_CROP);
            card.addView(image, new android.widget.FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT));
            String url = Jsons.string(history, "avatar");
            ImageLoader.get().load(ImageLoader.get().absoluteUrl(requireContext(), url), image, xyz.jjmxg.yiyunying.R.drawable.ic_person);
            card.setStrokeWidth(history.has("is_current") && history.get("is_current").getAsBoolean() ? dp(3) : 0);
            card.setStrokeColor(xyz.jjmxg.yiyunying.ui.common.ThemeColors.primary(requireContext()));
            card.setOnClickListener(view -> confirmSwitchAvatar(history));
            card.setOnLongClickListener(view -> {
                JsonObject preview = new JsonObject(); preview.addProperty("url", url); preview.addProperty("file_name", "历史头像");
                ImageGalleryActivity.open(requireContext(), java.util.Collections.singletonList(preview), 0); return true;
            });
            grid.addView(card);
        }
        if (grid.getChildCount() == 0) {
            android.widget.TextView empty = new android.widget.TextView(requireContext());
            empty.setText("还没有历史头像，更换头像后会自动保留。"); empty.setPadding(dp(20), dp(28), dp(20), dp(28));
            grid.addView(empty);
        }
        new YiyunyingDialogBuilder(requireContext()).setTitle("历史头像")
            .setView(scroll).setNegativeButton("关闭", null).show();
    }

    private void confirmSwitchAvatar(JsonObject history) {
        new YiyunyingDialogBuilder(requireContext()).setTitle("切换头像")
            .setMessage("立即使用这张历史头像？")
            .setPositiveButton("切换", (dialog, which) -> switchAvatar(Jsons.longValue(history, "id")))
            .setNegativeButton("取消", null).show();
    }

    private void switchAvatar(long historyId) {
        if (historyId <= 0 || avatarHistoryRequest != null) return;
        avatarHistoryRequest = app().repository().post("/api/user/profile/avatar-history/" + historyId + "/switch", new JsonObject(), result -> {
            avatarHistoryRequest = null;
            if (binding == null || handleFailure(result, binding.getRoot())) return;
            ImageLoader.get().invalidate(ImageLoader.get().absoluteUrl(requireContext(), avatarUrl));
            avatarUrl = Jsons.string(result.dataObject(), "avatar");
            ImageLoader.get().invalidate(ImageLoader.get().absoluteUrl(requireContext(), avatarUrl));
            ImageLoader.get().load(ImageLoader.get().absoluteUrl(requireContext(), avatarUrl), binding.avatar, xyz.jjmxg.yiyunying.R.drawable.ic_person);
            host().refreshProfileChrome();
            message(binding.getRoot(), "头像已切换");
        });
        track(avatarHistoryRequest);
    }

    private int dp(int value) { return Math.round(value * getResources().getDisplayMetrics().density); }

    private static String text(CharSequence value) { return value == null ? "" : value.toString().trim(); }
    @Override public void onDestroyView() {
        profileLoadGeneration++;
        if (avatarUpload != null) avatarUpload.cancel();
        if (avatarHistoryRequest != null) avatarHistoryRequest.cancel();
        binding = null;
        super.onDestroyView();
    }
}
