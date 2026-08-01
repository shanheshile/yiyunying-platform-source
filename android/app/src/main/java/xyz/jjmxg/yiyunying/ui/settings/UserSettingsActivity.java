package xyz.jjmxg.yiyunying.ui.settings;

import android.Manifest;
import android.app.NotificationManager;
import android.content.ComponentName;
import android.content.Context;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.PowerManager;
import android.provider.Settings;
import android.view.View;
import android.view.ViewGroup;

import androidx.activity.result.ActivityResultLauncher;
import androidx.activity.result.ActivityResult;
import androidx.activity.result.contract.ActivityResultContracts;
import androidx.activity.OnBackPressedCallback;
import androidx.core.content.ContextCompat;

import xyz.jjmxg.yiyunying.ui.common.YiyunyingDialogBuilder;
import com.google.android.material.snackbar.Snackbar;
import com.google.gson.JsonArray;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;
import com.google.gson.JsonParser;

import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Locale;

import xyz.jjmxg.yiyunying.BuildConfig;
import xyz.jjmxg.yiyunying.YiyunyingApplication;
import xyz.jjmxg.yiyunying.core.AppAccess;
import xyz.jjmxg.yiyunying.core.AppearanceStyleStore;
import xyz.jjmxg.yiyunying.core.CapturePreferences;
import xyz.jjmxg.yiyunying.core.ChatBackgroundStore;
import xyz.jjmxg.yiyunying.core.CrashReporter;
import xyz.jjmxg.yiyunying.core.RuntimeLanguage;
import xyz.jjmxg.yiyunying.core.ThemeModeStore;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.data.api.RequestHandle;
import xyz.jjmxg.yiyunying.databinding.ActivityUserSettingsBinding;
import xyz.jjmxg.yiyunying.domain.Role;
import xyz.jjmxg.yiyunying.ui.auth.ForgotPasswordActivity;
import xyz.jjmxg.yiyunying.ui.common.LifecycleChecker;
import xyz.jjmxg.yiyunying.ui.common.SystemInsetActivity;
import xyz.jjmxg.yiyunying.ui.home.HiddenConversationsActivity;
import xyz.jjmxg.yiyunying.ui.home.RelationshipHubActivity;
import xyz.jjmxg.yiyunying.ui.main.MainActivity;
import xyz.jjmxg.yiyunying.ui.permission.RolePermissionActivity;
import xyz.jjmxg.yiyunying.ui.social.BlacklistActivity;
import xyz.jjmxg.yiyunying.ui.social.SocialDirectoryActivity;
import xyz.jjmxg.yiyunying.ui.upload.CacheManagementActivity;
import xyz.jjmxg.yiyunying.ui.upload.MediaPickerActivity;

public final class UserSettingsActivity extends SystemInsetActivity {
    private static final String EXTRA_SECTION = "section";
    private static final String STATE_SECTION = "settings.active_section";
    private static final String STATE_SCROLL_Y = "settings.scroll_y";
    private static final String SECTION_ROOT = "root";
    private static final String SECTION_APPEARANCE = "appearance";
    private static final String SECTION_PRIVACY = "privacy";
    private static final String SECTION_DYNAMIC_PRIVACY = "dynamic_privacy";
    private static final String SECTION_NOTIFICATION = "notification";
    private static final String SECTION_ACCOUNT = "account";
    private static final String SECTION_STORAGE = "storage";
    private ActivityUserSettingsBinding binding;
    private RequestHandle request;
    private boolean categoryOpen;
    private boolean directSection;
    private boolean userRole;
    private String activeSection = SECTION_ROOT;
    private String dynamicVisibilityMode = "public";
    private int dynamicVisibleDays;
    private int restoredScrollY;
    private final LinkedHashMap<Long, JsonObject> dynamicAllowUsers = new LinkedHashMap<>();
    private final LinkedHashMap<Long, JsonObject> dynamicDenyUsers = new LinkedHashMap<>();
    private final ActivityResultLauncher<Intent> chatBackgroundPicker = registerForActivityResult(
        new ActivityResultContracts.StartActivityForResult(), result -> {
            if (result.getResultCode() != RESULT_OK || result.getData() == null) return;
            ArrayList<Uri> uris = result.getData()
                .getParcelableArrayListExtra(MediaPickerActivity.EXTRA_SELECTED_URIS);
            Uri uri = uris == null || uris.isEmpty() ? null : uris.get(0);
            if (uri == null || binding == null) return;
            try {
                getContentResolver().takePersistableUriPermission(uri, Intent.FLAG_GRANT_READ_URI_PERMISSION);
            } catch (SecurityException ignored) { }
            ChatBackgroundStore.setGlobal(this, uri.toString());
            ChatBackgroundStore.clearAllConversationOverrides(this);
            updateAppearanceLabels();
            showMessage("全局聊天背景已更新，并已应用到全部会话", Snackbar.LENGTH_SHORT);
        }
    );
    private final ActivityResultLauncher<Intent> dynamicAllowUsersPicker = registerForActivityResult(
        new ActivityResultContracts.StartActivityForResult(), result ->
            handleDynamicUserSelection(result, dynamicAllowUsers));
    private final ActivityResultLauncher<Intent> dynamicDenyUsersPicker = registerForActivityResult(
        new ActivityResultContracts.StartActivityForResult(), result ->
            handleDynamicUserSelection(result, dynamicDenyUsers));

    private final ActivityResultLauncher<String[]> runtimePermissionLauncher = registerForActivityResult(
        new ActivityResultContracts.RequestMultiplePermissions(), result -> {
            updatePermissionSummary();
            if (binding == null) return;
            int granted = 0;
            for (Boolean value : result.values()) if (Boolean.TRUE.equals(value)) granted++;
            showMessage(granted == result.size()
                ? "基础权限已允许"
                : "部分权限未允许，可随时从权限中心继续设置", Snackbar.LENGTH_LONG);
        }
    );

    public static void open(Context context) {
        context.startActivity(new Intent(context, UserSettingsActivity.class));
    }

    public static void openDynamicPrivacy(Context context) {
        context.startActivity(new Intent(context, UserSettingsActivity.class)
            .putExtra(EXTRA_SECTION, SECTION_DYNAMIC_PRIVACY));
    }

    @Override protected void onCreate(Bundle state) {
        super.onCreate(state);
        binding = ActivityUserSettingsBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());
        userRole = AppAccess.from(this).session().role() == Role.USER;
        configureRoleSettings();
        binding.toolbar.setNavigationOnClickListener(view -> navigateBack());
        getOnBackPressedDispatcher().addCallback(this, new OnBackPressedCallback(true) {
            @Override public void handleOnBackPressed() { navigateBack(); }
        });
        binding.categoryAppearanceButton.setOnClickListener(view -> showCategory(
            binding.appearanceGroup, SECTION_APPEARANCE, "外观与显示", true));
        binding.categoryPrivacyButton.setOnClickListener(view -> showCategory(
            binding.privacyGroup, SECTION_PRIVACY, "消息与隐私", true));
        binding.categoryDynamicButton.setOnClickListener(view -> showCategory(
            binding.dynamicPrivacyGroup, SECTION_DYNAMIC_PRIVACY, "动态展示", true));
        binding.categoryNotificationButton.setOnClickListener(view -> showCategory(
            binding.notificationGroup, SECTION_NOTIFICATION, "通知、来电与后台", true));
        binding.categoryAccountButton.setOnClickListener(view -> showCategory(
            binding.accountGroup, SECTION_ACCOUNT, "账号与安全", true));
        binding.categoryStorageButton.setOnClickListener(view -> showCategory(
            binding.storageGroup, SECTION_STORAGE, "存储与软件", true));
        updateAppearanceLabels();
        binding.themeButton.setOnClickListener(view -> chooseTheme());
        binding.languageButton.setOnClickListener(view -> chooseLanguage());
        binding.accentColorButton.setOnClickListener(view -> chooseAccentColor());
        binding.chatBackgroundButton.setOnClickListener(view -> chooseChatBackground());
        binding.fontButton.setOnClickListener(view -> chooseFont());
        binding.appIconButton.setOnClickListener(view -> chooseAppIcon());
        binding.dynamicVisibilityModeButton.setOnClickListener(view -> chooseDynamicVisibilityMode());
        binding.dynamicVisibleDaysButton.setOnClickListener(view -> chooseDynamicVisibleDays());
        binding.dynamicAllowPickButton.setOnClickListener(view -> binding.dynamicAllowUsersInput.performClick());
        binding.dynamicDenyPickButton.setOnClickListener(view -> binding.dynamicDenyUsersInput.performClick());
        binding.dynamicEnabled.setOnCheckedChangeListener((button, checked) -> renderDynamicPrivacy());
        configureDynamicUserPicker(binding.dynamicAllowUsersInput, dynamicAllowUsersPicker,
            dynamicAllowUsers, "选择可以查看动态的好友");
        configureDynamicUserPicker(binding.dynamicDenyUsersInput, dynamicDenyUsersPicker,
            dynamicDenyUsers, "选择不能查看动态的好友");
        binding.saveCaptureToGallery.setChecked(CapturePreferences.saveToGallery(this));
        binding.saveCaptureToGallery.setOnCheckedChangeListener((button, checked) -> {
            CapturePreferences.setSaveToGallery(this, checked);
            showMessage(checked
                ? "后续拍照和录像会保存到系统相册"
                : "后续拍摄只用于发送，不会保存到系统相册", Snackbar.LENGTH_SHORT);
        });
        binding.saveButton.setOnClickListener(view -> save());
        binding.dynamicSaveButton.setOnClickListener(view -> save());
        binding.notificationSaveButton.setOnClickListener(view -> save());
        binding.systemPermissionButton.setOnClickListener(view -> showSystemPermissionMenu());
        binding.relationshipNoticeButton.setOnClickListener(view -> RelationshipHubActivity.open(this));
        binding.friendDirectoryButton.setOnClickListener(view -> SocialDirectoryActivity.openFriends(this));
        binding.hiddenConversationButton.setOnClickListener(view -> HiddenConversationsActivity.open(this));
        binding.forgotPasswordButton.setOnClickListener(view -> ForgotPasswordActivity.open(this));
        binding.blacklistButton.setOnClickListener(view -> BlacklistActivity.open(this));
        binding.permissionCenterButton.setOnClickListener(view -> RolePermissionActivity.openSelf(this));
        binding.cacheButton.setOnClickListener(view -> CacheManagementActivity.open(this));
        binding.updateStatusText.setText(tr("当前版本：") + BuildConfig.VERSION_NAME
            + "\n" + tr("点击上方入口会立即连接更新服务，无需重启软件。"));
        binding.updateButton.setOnClickListener(view ->
            LifecycleChecker.checkNow(this, binding == null ? null : binding.getRoot()));
        if (userRole) load();
        else setLoading(false);
        directSection = userRole
            && SECTION_DYNAMIC_PRIVACY.equals(getIntent().getStringExtra(EXTRA_SECTION));
        if (directSection) {
            showCategory(binding.dynamicPrivacyGroup, SECTION_DYNAMIC_PRIVACY, "动态展示", false);
        } else if (state != null) {
            activeSection = state.getString(STATE_SECTION, SECTION_ROOT);
            restoredScrollY = Math.max(0, state.getInt(STATE_SCROLL_Y, 0));
            restoreSection();
        } else {
            showCategoryList(false);
        }
        binding.settingsScroll.post(() -> binding.settingsScroll.scrollTo(0, restoredScrollY));
    }

    private void configureRoleSettings() {
        if (userRole) return;
        binding.categoryPrivacyButton.setVisibility(View.GONE);
        binding.categoryDynamicButton.setVisibility(View.GONE);
        binding.categoryNotificationButton.setVisibility(View.GONE);
        binding.categoryAccountButton.setVisibility(View.GONE);

        ViewGroup parent = (ViewGroup) binding.systemPermissionButton.getParent();
        if (parent != binding.storageGroup) {
            parent.removeView(binding.systemPermissionButton);
            int index = Math.min(1, binding.storageGroup.getChildCount());
            binding.storageGroup.addView(binding.systemPermissionButton, index);
        }
    }

    private void showCategory(View group, String section, String title, boolean resetScroll) {
        binding.categoryList.setVisibility(View.GONE);
        binding.appearanceGroup.setVisibility(View.GONE);
        binding.privacyGroup.setVisibility(View.GONE);
        binding.dynamicPrivacyGroup.setVisibility(View.GONE);
        binding.notificationGroup.setVisibility(View.GONE);
        binding.accountGroup.setVisibility(View.GONE);
        binding.storageGroup.setVisibility(View.GONE);
        group.setVisibility(View.VISIBLE);
        binding.toolbar.setTitle(tr(title));
        activeSection = section;
        categoryOpen = true;
        if (resetScroll) binding.settingsScroll.scrollTo(0, 0);
    }

    private void showCategoryList() {
        showCategoryList(true);
    }

    private void showCategoryList(boolean resetScroll) {
        binding.categoryList.setVisibility(View.VISIBLE);
        binding.appearanceGroup.setVisibility(View.GONE);
        binding.privacyGroup.setVisibility(View.GONE);
        binding.dynamicPrivacyGroup.setVisibility(View.GONE);
        binding.notificationGroup.setVisibility(View.GONE);
        binding.accountGroup.setVisibility(View.GONE);
        binding.storageGroup.setVisibility(View.GONE);
        binding.toolbar.setTitle(tr("设置"));
        activeSection = SECTION_ROOT;
        categoryOpen = false;
        if (resetScroll) binding.settingsScroll.scrollTo(0, 0);
    }

    private void restoreSection() {
        if (SECTION_APPEARANCE.equals(activeSection)) {
            showCategory(binding.appearanceGroup, SECTION_APPEARANCE, "外观与显示", false);
        } else if (SECTION_PRIVACY.equals(activeSection) && userRole) {
            showCategory(binding.privacyGroup, SECTION_PRIVACY, "消息与隐私", false);
        } else if (SECTION_DYNAMIC_PRIVACY.equals(activeSection) && userRole) {
            showCategory(binding.dynamicPrivacyGroup, SECTION_DYNAMIC_PRIVACY, "动态展示", false);
        } else if (SECTION_NOTIFICATION.equals(activeSection) && userRole) {
            showCategory(binding.notificationGroup, SECTION_NOTIFICATION, "通知、来电与后台", false);
        } else if (SECTION_ACCOUNT.equals(activeSection) && userRole) {
            showCategory(binding.accountGroup, SECTION_ACCOUNT, "账号与安全", false);
        } else if (SECTION_STORAGE.equals(activeSection)) {
            showCategory(binding.storageGroup, SECTION_STORAGE, "存储与软件", false);
        } else {
            showCategoryList(false);
        }
    }

    private void navigateBack() {
        if (categoryOpen) {
            if (directSection) {
                finish();
                return;
            }
            showCategoryList();
            return;
        }
        finish();
    }

    @Override protected void onResume() {
        super.onResume();
        updateAppearanceLabels();
        updatePermissionSummary();
    }

    @Override protected void onSaveInstanceState(Bundle outState) {
        outState.putString(STATE_SECTION, activeSection);
        if (binding != null) outState.putInt(STATE_SCROLL_Y, binding.settingsScroll.getScrollY());
        super.onSaveInstanceState(outState);
    }

    private void showSystemPermissionMenu() {
        CharSequence[] actions = {
            tr("基础功能权限") + "（" + runtimePermissionStatus() + "）",
            tr("系统通知") + "（" + state(notificationsAllowed()) + "）",
            tr("全屏来电提醒") + "（" + state(fullScreenIntentAllowed()) + "）",
            tr("通话悬浮窗") + "（" + state(Settings.canDrawOverlays(this)) + "）",
            tr("省电白名单") + "（" + state(ignoresBatteryOptimizations()) + "）",
            tr("自启动与后台运行"),
            tr("勿扰模式访问") + "（" + state(notificationPolicyAllowed()) + "）",
            tr("应用权限详情")
        };
        new YiyunyingDialogBuilder(this)
            .setTitle(tr("通知、来电与后台运行"))
            .setMessage(tr("即时消息和网络通话依赖这些权限。系统会逐项征求你的确认，应用不会读取与功能无关的数据。"))
            .setItems(actions, (dialog, which) -> {
                if (which == 0) {
                    requestRuntimePermissions();
                } else if (which == 1) {
                    openSystemSettings(new Intent(Settings.ACTION_APP_NOTIFICATION_SETTINGS)
                        .putExtra(Settings.EXTRA_APP_PACKAGE, getPackageName()));
                } else if (which == 2 && Build.VERSION.SDK_INT >= 34) {
                    openSystemSettings(new Intent(Settings.ACTION_MANAGE_APP_USE_FULL_SCREEN_INTENT,
                        Uri.parse("package:" + getPackageName())));
                } else if (which == 2) {
                    openSystemSettings(new Intent(Settings.ACTION_APP_NOTIFICATION_SETTINGS)
                        .putExtra(Settings.EXTRA_APP_PACKAGE, getPackageName()));
                } else if (which == 3) {
                    openSystemSettings(new Intent(Settings.ACTION_MANAGE_OVERLAY_PERMISSION,
                        Uri.parse("package:" + getPackageName())));
                } else if (which == 4) {
                    openSystemSettings(new Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS,
                        Uri.parse("package:" + getPackageName())));
                } else if (which == 5) {
                    openAutostartSettings();
                } else if (which == 6) {
                    openSystemSettings(new Intent(Settings.ACTION_NOTIFICATION_POLICY_ACCESS_SETTINGS));
                } else {
                    openSystemSettings(new Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS,
                        Uri.parse("package:" + getPackageName())));
                }
            })
            .setNegativeButton(tr("取消"), null)
            .show();
    }

    private void requestRuntimePermissions() {
        List<String> permissions = new ArrayList<>();
        permissions.add(Manifest.permission.CAMERA);
        permissions.add(Manifest.permission.RECORD_AUDIO);
        permissions.add(Manifest.permission.READ_PHONE_STATE);
        permissions.add(Manifest.permission.ACCESS_COARSE_LOCATION);
        permissions.add(Manifest.permission.ACCESS_FINE_LOCATION);
        if (Build.VERSION.SDK_INT >= 31) permissions.add(Manifest.permission.BLUETOOTH_CONNECT);
        if (Build.VERSION.SDK_INT >= 33) {
            permissions.add(Manifest.permission.POST_NOTIFICATIONS);
            permissions.add(Manifest.permission.READ_MEDIA_IMAGES);
            permissions.add(Manifest.permission.READ_MEDIA_VIDEO);
        } else {
            permissions.add(Manifest.permission.READ_EXTERNAL_STORAGE);
        }
        List<String> missing = new ArrayList<>();
        for (String permission : permissions) {
            if (ContextCompat.checkSelfPermission(this, permission) != PackageManager.PERMISSION_GRANTED) {
                missing.add(permission);
            }
        }
        if (missing.isEmpty()) {
            showMessage("基础功能权限均已允许", Snackbar.LENGTH_SHORT);
            return;
        }
        runtimePermissionLauncher.launch(missing.toArray(new String[0]));
    }

    private String runtimePermissionStatus() {
        String[] permissions = Build.VERSION.SDK_INT >= 33
            ? new String[]{Manifest.permission.CAMERA, Manifest.permission.RECORD_AUDIO,
                Manifest.permission.READ_PHONE_STATE, Manifest.permission.ACCESS_FINE_LOCATION,
                Manifest.permission.POST_NOTIFICATIONS, Manifest.permission.READ_MEDIA_IMAGES,
                Manifest.permission.READ_MEDIA_VIDEO}
            : new String[]{Manifest.permission.CAMERA, Manifest.permission.RECORD_AUDIO,
                Manifest.permission.READ_PHONE_STATE, Manifest.permission.ACCESS_FINE_LOCATION,
                Manifest.permission.READ_EXTERNAL_STORAGE};
        int granted = 0;
        for (String permission : permissions) {
            if (ContextCompat.checkSelfPermission(this, permission) == PackageManager.PERMISSION_GRANTED) granted++;
        }
        return granted + "/" + permissions.length + " " + tr("已允许");
    }

    private void updatePermissionSummary() {
        if (binding == null) return;
        binding.systemPermissionButton.setText(tr("通知、来电与后台运行权限") + " · " + runtimePermissionStatus());
    }

    private boolean notificationsAllowed() {
        NotificationManager manager = getSystemService(NotificationManager.class);
        return manager != null && (Build.VERSION.SDK_INT < 24 || manager.areNotificationsEnabled());
    }

    private boolean fullScreenIntentAllowed() {
        if (Build.VERSION.SDK_INT < 34) return notificationsAllowed();
        NotificationManager manager = getSystemService(NotificationManager.class);
        return manager != null && manager.canUseFullScreenIntent();
    }

    private boolean notificationPolicyAllowed() {
        NotificationManager manager = getSystemService(NotificationManager.class);
        return manager != null && manager.isNotificationPolicyAccessGranted();
    }

    private boolean ignoresBatteryOptimizations() {
        PowerManager manager = getSystemService(PowerManager.class);
        return manager != null && manager.isIgnoringBatteryOptimizations(getPackageName());
    }

    private String state(boolean enabled) {
        return tr(enabled ? "已开启" : "未开启").toString();
    }

    private void openAutostartSettings() {
        String manufacturer = Build.MANUFACTURER == null ? "" : Build.MANUFACTURER.toLowerCase(Locale.ROOT);
        List<ComponentName> candidates = new ArrayList<>();
        if (manufacturer.contains("xiaomi") || manufacturer.contains("redmi")) {
            candidates.add(new ComponentName("com.miui.securitycenter",
                "com.miui.permcenter.autostart.AutoStartManagementActivity"));
        }
        if (manufacturer.contains("huawei") || manufacturer.contains("honor")) {
            candidates.add(new ComponentName("com.huawei.systemmanager",
                "com.huawei.systemmanager.startupmgr.ui.StartupNormalAppListActivity"));
        }
        if (manufacturer.contains("oppo") || manufacturer.contains("realme") || manufacturer.contains("oneplus")) {
            candidates.add(new ComponentName("com.coloros.safecenter",
                "com.coloros.safecenter.permission.startup.StartupAppListActivity"));
        }
        if (manufacturer.contains("vivo") || manufacturer.contains("iqoo")) {
            candidates.add(new ComponentName("com.vivo.permissionmanager",
                "com.vivo.permissionmanager.activity.BgStartUpManagerActivity"));
        }
        for (ComponentName candidate : candidates) {
            try {
                startActivity(new Intent().setComponent(candidate));
                return;
            } catch (RuntimeException ignored) { }
        }
        openSystemSettings(new Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS,
            Uri.parse("package:" + getPackageName())));
        showMessage("请在系统设置中允许自启动、后台活动和后台联网", Snackbar.LENGTH_LONG);
    }

    private void openSystemSettings(Intent intent) {
        try {
            startActivity(intent);
        } catch (RuntimeException exception) {
            startActivity(new Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS,
                Uri.parse("package:" + getPackageName())));
        }
    }

    private void chooseTheme() {
        String current = ThemeModeStore.get(this);
        int checked = ThemeModeStore.DARK.equals(current) ? 2 : (ThemeModeStore.LIGHT.equals(current) ? 1 : 0);
        CharSequence[] labels = translatedOptions("跟随系统", "浅色模式", "深色模式");
        new YiyunyingDialogBuilder(this)
            .setTitle(tr("外观模式"))
            .setSingleChoiceItems(labels, checked, (dialog, which) -> {
                String mode = which == 2 ? ThemeModeStore.DARK : (which == 1 ? ThemeModeStore.LIGHT : ThemeModeStore.SYSTEM);
                ThemeModeStore.set(this, mode);
                dialog.dismiss();
            })
            .setNegativeButton(tr("取消"), null)
            .show();
    }

    private void load() {
        if (!userRole) return;
        if (request != null) request.cancel();
        setLoading(true);
        request = AppAccess.from(this).repository().get("/api/user/message-settings", new LinkedHashMap<>(), result -> {
            request = null;
            if (binding == null) return;
            setLoading(false);
            if (!result.isSuccessful()) {
                showMessage(result.message().isEmpty() ? "设置加载失败" : result.message(), Snackbar.LENGTH_LONG);
                return;
            }
            JsonObject settings = Jsons.object(result.dataObject(), "settings");
            binding.allowFriendRequests.setChecked(bool(settings, "allow_friend_requests", true));
            binding.acceptStrangerMessages.setChecked(bool(settings, "accept_stranger_messages", true));
            binding.systemNotifications.setChecked(bool(settings, "system_notification_enabled", true));
            binding.privateNotifications.setChecked(bool(settings, "private_notification_enabled", true));
            binding.groupNotifications.setChecked(bool(settings, "group_notification_enabled", true));
            binding.profileNotesVisible.setChecked(bool(settings, "profile_notes_visible", true));
            binding.profileForumVisible.setChecked(bool(settings, "profile_forum_visible", true));
            binding.profileBountiesVisible.setChecked(bool(settings, "profile_bounties_visible", true));
            binding.profileFollowingVisible.setChecked(bool(settings, "profile_following_visible", true));
            binding.profileFollowersVisible.setChecked(bool(settings, "profile_followers_visible", true));
            binding.allowCardAdd.setChecked(bool(settings, "allow_card_add", true));
            binding.allowQrAdd.setChecked(bool(settings, "allow_qr_add", true));
            binding.allowUidSearch.setChecked(bool(settings, "allow_uid_search", true));
            binding.allowPhoneSearch.setChecked(bool(settings, "allow_phone_search", false));
            binding.allowEmailSearch.setChecked(bool(settings, "allow_email_search", false));
            binding.allowGroupMemberAdd.setChecked(bool(settings, "allow_group_member_add", true));
            binding.allowGroupInvitations.setChecked(bool(settings, "allow_group_invitations", true));
            binding.showOnlineStatus.setChecked(bool(settings, "show_online_status", true));
            binding.readReceiptsEnabled.setChecked(bool(settings, "read_receipts_enabled", true));
            binding.roomNotifications.setChecked(bool(settings, "room_notification_enabled", true));
            binding.forumNotifications.setChecked(bool(settings, "forum_notification_enabled", true));
            binding.bountyNotifications.setChecked(bool(settings, "bounty_notification_enabled", true));
            binding.mentionNotifications.setChecked(bool(settings, "mention_notification_enabled", true));
            binding.notificationPreviewEnabled.setChecked(bool(settings, "notification_preview_enabled", true));
            binding.notificationSoundEnabled.setChecked(bool(settings, "notification_sound_enabled", true));
            binding.notificationVibrationEnabled.setChecked(bool(settings, "notification_vibration_enabled", true));
            binding.remoteLoginProtection.setChecked(bool(settings, "remote_login_protection", true));
            binding.dynamicEnabled.setChecked(bool(settings, "dynamic_enabled", true));
            dynamicVisibilityMode = safeDynamicMode(Jsons.string(settings, "dynamic_visibility_mode"));
            dynamicVisibleDays = safeDynamicDays(Jsons.intValue(settings, "dynamic_visible_days", 0));
            loadDynamicUserIds(dynamicAllowUsers, Jsons.array(settings, "dynamic_allow_user_ids"));
            loadDynamicUserIds(dynamicDenyUsers, Jsons.array(settings, "dynamic_deny_user_ids"));
            binding.dynamicVisibleToFriends.setChecked(bool(settings, "dynamic_visible_to_friends", true));
            binding.dynamicVisibleToFollowers.setChecked(bool(settings, "dynamic_visible_to_followers", true));
            binding.dynamicVisibleToStrangers.setChecked(bool(settings, "dynamic_visible_to_strangers", true));
            binding.dynamicVisibleToHiddenContacts.setChecked(bool(settings, "dynamic_visible_to_hidden_contacts", true));
            binding.dynamicVisibleToSpecialCare.setChecked(bool(settings, "dynamic_visible_to_special_care", true));
            renderDynamicPrivacy();
            binding.recallSuffix.setText(getSharedPreferences("chat_ui", MODE_PRIVATE)
                .getString("recall_suffix", "并坏笑了一下"));
        });
    }

    private void save() {
        if (!userRole) return;
        if (request != null) return;
        JsonObject body = new JsonObject();
        body.addProperty("allow_friend_requests", binding.allowFriendRequests.isChecked());
        body.addProperty("accept_stranger_messages", binding.acceptStrangerMessages.isChecked());
        body.addProperty("system_notification_enabled", binding.systemNotifications.isChecked());
        body.addProperty("private_notification_enabled", binding.privateNotifications.isChecked());
        body.addProperty("group_notification_enabled", binding.groupNotifications.isChecked());
        body.addProperty("profile_notes_visible", binding.profileNotesVisible.isChecked());
        body.addProperty("profile_forum_visible", binding.profileForumVisible.isChecked());
        body.addProperty("profile_bounties_visible", binding.profileBountiesVisible.isChecked());
        body.addProperty("profile_following_visible", binding.profileFollowingVisible.isChecked());
        body.addProperty("profile_followers_visible", binding.profileFollowersVisible.isChecked());
        body.addProperty("allow_card_add", binding.allowCardAdd.isChecked());
        body.addProperty("allow_qr_add", binding.allowQrAdd.isChecked());
        body.addProperty("allow_uid_search", binding.allowUidSearch.isChecked());
        body.addProperty("allow_phone_search", binding.allowPhoneSearch.isChecked());
        body.addProperty("allow_email_search", binding.allowEmailSearch.isChecked());
        body.addProperty("allow_group_member_add", binding.allowGroupMemberAdd.isChecked());
        body.addProperty("allow_group_invitations", binding.allowGroupInvitations.isChecked());
        body.addProperty("show_online_status", binding.showOnlineStatus.isChecked());
        body.addProperty("read_receipts_enabled", binding.readReceiptsEnabled.isChecked());
        body.addProperty("room_notification_enabled", binding.roomNotifications.isChecked());
        body.addProperty("forum_notification_enabled", binding.forumNotifications.isChecked());
        body.addProperty("bounty_notification_enabled", binding.bountyNotifications.isChecked());
        body.addProperty("mention_notification_enabled", binding.mentionNotifications.isChecked());
        body.addProperty("notification_preview_enabled", binding.notificationPreviewEnabled.isChecked());
        body.addProperty("notification_sound_enabled", binding.notificationSoundEnabled.isChecked());
        body.addProperty("notification_vibration_enabled", binding.notificationVibrationEnabled.isChecked());
        body.addProperty("remote_login_protection", binding.remoteLoginProtection.isChecked());
        body.addProperty("dynamic_enabled", binding.dynamicEnabled.isChecked());
        body.addProperty("dynamic_visibility_mode", dynamicVisibilityMode);
        body.addProperty("dynamic_visible_days", dynamicVisibleDays);
        body.add("dynamic_allow_user_ids", dynamicUserIds(dynamicAllowUsers));
        body.add("dynamic_deny_user_ids", dynamicUserIds(dynamicDenyUsers));
        body.addProperty("dynamic_visible_to_friends", binding.dynamicVisibleToFriends.isChecked());
        body.addProperty("dynamic_visible_to_followers", binding.dynamicVisibleToFollowers.isChecked());
        body.addProperty("dynamic_visible_to_strangers", binding.dynamicVisibleToStrangers.isChecked());
        body.addProperty("dynamic_visible_to_hidden_contacts", binding.dynamicVisibleToHiddenContacts.isChecked());
        body.addProperty("dynamic_visible_to_special_care", binding.dynamicVisibleToSpecialCare.isChecked());
        String recallSuffix = binding.recallSuffix.getText() == null ? "" : binding.recallSuffix.getText().toString().trim();
        if (recallSuffix.length() > 6) recallSuffix = recallSuffix.substring(0, 6);
        getSharedPreferences("chat_ui", MODE_PRIVATE).edit().putString("recall_suffix", recallSuffix).apply();
        setLoading(true);
        request = AppAccess.from(this).repository().put("/api/user/message-settings", body, result -> {
            request = null;
            if (binding == null) return;
            setLoading(false);
            showMessage(result.isSuccessful()
                ? (result.message().isEmpty() ? "设置已保存" : result.message())
                : (result.message().isEmpty() ? "设置保存失败" : result.message()), Snackbar.LENGTH_LONG);
        });
    }

    private void setLoading(boolean loading) {
        if (binding == null) return;
        binding.progress.setVisibility(loading ? View.VISIBLE : View.INVISIBLE);
        binding.saveButton.setEnabled(!loading);
        binding.dynamicSaveButton.setEnabled(!loading);
        binding.notificationSaveButton.setEnabled(!loading);
    }

    private static boolean bool(JsonObject object, String key, boolean fallback) {
        try { return object.has(key) ? object.get(key).getAsBoolean() : fallback; }
        catch (RuntimeException ignored) { return fallback; }
    }

    private void chooseDynamicVisibilityMode() {
        String[] values = {"public", "friends", "followers", "selected", "exclude", "private"};
        CharSequence[] labels = translatedOptions(
            "所有人", "仅好友", "仅关注我的用户", "仅指定用户", "除指定用户外", "仅自己"
        );
        int checked = 0;
        for (int index = 0; index < values.length; index++) {
            if (values[index].equals(dynamicVisibilityMode)) { checked = index; break; }
        }
        new YiyunyingDialogBuilder(this)
            .setTitle(tr("默认可见对象"))
            .setSingleChoiceItems(labels, checked, (dialog, which) -> {
                dynamicVisibilityMode = values[which];
                dialog.dismiss();
                renderDynamicPrivacy();
            })
            .setNegativeButton(tr("取消"), null)
            .show();
    }

    private void chooseDynamicVisibleDays() {
        int[] values = {0, 3, 30, 180, 365};
        CharSequence[] labels = translatedOptions("永久可见", "最近三天", "最近一个月", "最近半年", "最近一年");
        int checked = 0;
        for (int index = 0; index < values.length; index++) {
            if (values[index] == dynamicVisibleDays) { checked = index; break; }
        }
        new YiyunyingDialogBuilder(this)
            .setTitle(tr("默认可见时间"))
            .setSingleChoiceItems(labels, checked, (dialog, which) -> {
                dynamicVisibleDays = values[which];
                dialog.dismiss();
                renderDynamicPrivacy();
            })
            .setNegativeButton(tr("取消"), null)
            .show();
    }

    private void configureDynamicUserPicker(
        com.google.android.material.textfield.TextInputEditText input,
        ActivityResultLauncher<Intent> launcher,
        LinkedHashMap<Long, JsonObject> selected,
        String title
    ) {
        input.setFocusable(false);
        input.setCursorVisible(false);
        input.setLongClickable(false);
        input.setOnClickListener(view -> launcher.launch(SocialDirectoryActivity.pickFriendsIntent(
            this,
            200,
            title,
            new long[]{AppAccess.from(this).session().actorId()},
            "不能选择自己",
            selectedUserIds(selected),
            true
        )));
    }

    private void handleDynamicUserSelection(
        ActivityResult result,
        LinkedHashMap<Long, JsonObject> target
    ) {
        if (result.getResultCode() != RESULT_OK || result.getData() == null) return;
        String raw = result.getData().getStringExtra(SocialDirectoryActivity.EXTRA_SELECTED_ITEMS);
        if (raw == null) return;
        LinkedHashMap<Long, JsonObject> next = new LinkedHashMap<>();
        try {
            JsonElement parsed = JsonParser.parseString(raw);
            if (parsed.isJsonArray()) {
                for (JsonElement element : parsed.getAsJsonArray()) {
                    if (!element.isJsonObject()) continue;
                    JsonObject item = element.getAsJsonObject();
                    long userId = Jsons.longValue(item, "user_id");
                    if (userId > 0L && userId != AppAccess.from(this).session().actorId()) {
                        next.put(userId, item.deepCopy());
                    }
                }
            }
        } catch (RuntimeException ignored) { }
        target.clear();
        target.putAll(next);
        renderDynamicPrivacy();
    }

    private void loadDynamicUserIds(LinkedHashMap<Long, JsonObject> target, JsonArray values) {
        target.clear();
        if (values == null) return;
        for (JsonElement value : values) {
            long userId;
            try { userId = value.getAsLong(); }
            catch (RuntimeException ignored) { continue; }
            if (userId <= 0L || userId == AppAccess.from(this).session().actorId()) continue;
            JsonObject placeholder = new JsonObject();
            placeholder.addProperty("user_id", userId);
            target.put(userId, placeholder);
        }
    }

    private static long[] selectedUserIds(LinkedHashMap<Long, JsonObject> selected) {
        long[] values = new long[selected.size()];
        int index = 0;
        for (Long userId : selected.keySet()) values[index++] = userId == null ? 0L : userId;
        return values;
    }

    private static JsonArray dynamicUserIds(LinkedHashMap<Long, JsonObject> selected) {
        JsonArray values = new JsonArray();
        for (Long userId : selected.keySet()) if (userId != null && userId > 0L) values.add(userId);
        return values;
    }

    private void renderDynamicUserSummary(
        com.google.android.material.textfield.TextInputEditText input,
        LinkedHashMap<Long, JsonObject> selected
    ) {
        if (selected.isEmpty()) {
            input.setText(tr("未选择好友，点击选择"));
            return;
        }
        ArrayList<String> names = new ArrayList<>();
        for (JsonObject item : selected.values()) {
            String name = Jsons.string(item, "remark");
            if (name.isEmpty()) name = Jsons.string(item, "account_name");
            if (name.isEmpty()) name = Jsons.string(item, "nickname");
            if (!name.isEmpty()) names.add(name);
            if (names.size() >= 2) break;
        }
        String prefix = names.isEmpty() ? "" : String.join("、", names) + " · ";
        input.setText(prefix + tr("已选择") + " " + selected.size() + " " + tr("位好友，点击修改"));
    }

    private void renderDynamicPrivacy() {
        if (binding == null) return;
        boolean enabled = binding.dynamicEnabled.isChecked();
        binding.dynamicVisibilityModeButton.setEnabled(enabled);
        binding.dynamicVisibleDaysButton.setEnabled(enabled);
        binding.dynamicVisibilityModeButton.setText(statusText("默认可见对象", dynamicModeLabel(dynamicVisibilityMode)));
        binding.dynamicVisibleDaysButton.setText(statusText("默认可见时间", dynamicDaysLabel(dynamicVisibleDays)));
        binding.dynamicAllowUsersLayout.setVisibility(enabled && "selected".equals(dynamicVisibilityMode)
            ? View.VISIBLE : View.GONE);
        binding.dynamicDenyUsersLayout.setVisibility(enabled && "exclude".equals(dynamicVisibilityMode)
            ? View.VISIBLE : View.GONE);
        renderDynamicUserSummary(binding.dynamicAllowUsersInput, dynamicAllowUsers);
        renderDynamicUserSummary(binding.dynamicDenyUsersInput, dynamicDenyUsers);
        binding.dynamicAllowPickButton.setVisibility(enabled && "selected".equals(dynamicVisibilityMode)
            ? View.VISIBLE : View.GONE);
        binding.dynamicDenyPickButton.setVisibility(enabled && "exclude".equals(dynamicVisibilityMode)
            ? View.VISIBLE : View.GONE);
    }

    private String dynamicModeLabel(String mode) {
        if ("friends".equals(mode)) return "仅好友";
        if ("followers".equals(mode)) return "仅关注我的用户";
        if ("selected".equals(mode)) return "仅指定用户";
        if ("exclude".equals(mode)) return "除指定用户外";
        if ("private".equals(mode)) return "仅自己";
        return "所有人";
    }

    private String dynamicDaysLabel(int days) {
        if (days == 3) return "最近三天";
        if (days == 30) return "最近一个月";
        if (days == 180) return "最近半年";
        if (days == 365) return "最近一年";
        return "永久可见";
    }

    private static String safeDynamicMode(String value) {
        if ("friends".equals(value) || "followers".equals(value) || "selected".equals(value)
            || "exclude".equals(value) || "private".equals(value)) return value;
        return "public";
    }

    private static int safeDynamicDays(int value) {
        return value == 3 || value == 30 || value == 180 || value == 365 ? value : 0;
    }

    private static String join(JsonArray values) {
        StringBuilder output = new StringBuilder();
        for (JsonElement value : values) {
            if (value == null || value.isJsonNull()) continue;
            String token;
            try { token = value.getAsString().trim(); }
            catch (RuntimeException ignored) { continue; }
            if (token.isEmpty()) continue;
            if (output.length() > 0) output.append("，");
            output.append(token);
        }
        return output.toString();
    }


    private static JsonArray tokens(CharSequence text) {
        JsonArray values = new JsonArray();
        if (text == null) return values;
        String[] parts = text.toString().trim().split("[\\s,，;；]+");
        java.util.LinkedHashSet<String> unique = new java.util.LinkedHashSet<>();
        for (String part : parts) {
            String token = part.trim();
            if (!token.isEmpty()) unique.add(token);
        }
        for (String value : unique) values.add(value);
        return values;
    }

    private void updateAppearanceLabels() {
        if (binding == null) return;
        binding.themeButton.setText(statusText("外观", ThemeModeStore.label(this)));
        String language = AppearanceStyleStore.language(this);
        String languageLabel = "en".equals(language) ? "English" : ("ja".equals(language) ? "日本語" : "简体中文");
        binding.languageButton.setText(statusText("界面语言", languageLabel));
        String accent = AppearanceStyleStore.accent(this);
        binding.accentColorButton.setText(statusText("软件主色",
            "teal".equals(accent) ? "青绿色" : ("rose".equals(accent) ? "玫红色" : "易运盈蓝")));
        boolean customBackground = !ChatBackgroundStore.global(this).isEmpty();
        binding.chatBackgroundButton.setText(statusText("全局聊天背景", customBackground ? "自定义图片" : "默认"));
        binding.fontButton.setText(statusText("字体", AppearanceStyleStore.fontLabel(this)));
        String icon = AppearanceStyleStore.icon(this);
        binding.appIconButton.setText(statusText("桌面图标",
            "minimal".equals(icon) ? "简约" : ("dark".equals(icon) ? "深色" : "默认")));
    }

    private CharSequence statusText(String label, String value) {
        String separator = "zh-CN".equals(RuntimeLanguage.language(this)) ? "：" : ": ";
        return tr(label).toString() + separator + tr(value);
    }

    private CharSequence tr(String value) {
        return RuntimeLanguage.translate(this, value);
    }

    private void showMessage(String value, int duration) {
        if (binding == null) return;
        Snackbar.make(binding.getRoot(), tr(value), duration).show();
    }

    private CharSequence[] translatedOptions(String... values) {
        CharSequence[] translated = new CharSequence[values.length];
        for (int index = 0; index < values.length; index++) translated[index] = tr(values[index]);
        return translated;
    }

    private void chooseLanguage() {
        String current = getSharedPreferences(AppearanceStyleStore.PREFERENCES, MODE_PRIVATE)
            .getString(AppearanceStyleStore.KEY_LANGUAGE, "zh-CN");
        int checked = "en".equals(current) ? 1 : ("ja".equals(current) ? 2 : 0);
        CharSequence[] labels = {"简体中文", "English", "日本語"};
        String[] tags = {"zh-CN", "en", "ja"};
        new YiyunyingDialogBuilder(this)
            .setTitle(tr("界面语言"))
            .setSingleChoiceItems(labels, checked, (dialog, which) -> {
                AppearanceStyleStore.setLanguage(this, tags[which]);
                ((YiyunyingApplication) getApplication()).refreshShortcuts();
                dialog.dismiss();
                updateAppearanceLabels();
            })
            .setNegativeButton(tr("取消"), null)
            .show();
    }

    private void chooseAccentColor() {
        String current = AppearanceStyleStore.accent(this);
        int checked = "teal".equals(current) ? 1 : ("rose".equals(current) ? 2 : 0);
        CharSequence[] labels = translatedOptions("易运盈蓝", "青绿色", "玫红色");
        String[] values = {"blue", "teal", "rose"};
        new YiyunyingDialogBuilder(this)
            .setTitle(tr("软件主色"))
            .setSingleChoiceItems(labels, checked, (dialog, which) -> {
                AppearanceStyleStore.setAccent(this, values[which]);
                dialog.dismiss();
                updateAppearanceLabels();
            })
            .setNegativeButton(tr("取消"), null)
            .show();
    }

    private void chooseChatBackground() {
        new YiyunyingDialogBuilder(this)
            .setTitle(tr("全局聊天背景"))
            .setMessage(tr("选择后会立即应用到全部聊天；之后仍可在单个会话中单独设置。"))
            .setItems(translatedOptions("选择全局背景图片", "恢复系统默认背景"), (dialog, which) -> {
                if (which == 0) {
                    chatBackgroundPicker.launch(MediaPickerActivity.imageIntent(this, 1));
                } else {
                    ChatBackgroundStore.setGlobal(this, "");
                    ChatBackgroundStore.clearAllConversationOverrides(this);
                    updateAppearanceLabels();
                    showMessage("全部聊天背景已恢复系统默认", Snackbar.LENGTH_SHORT);
                }
            })
            .setNegativeButton(tr("取消"), null)
            .show();
    }

    private void chooseAppIcon() {
        String current = AppearanceStyleStore.icon(this);
        int checked = "minimal".equals(current) ? 1 : ("dark".equals(current) ? 2 : 0);
        CharSequence[] labels = translatedOptions("默认图标", "简约图标", "深色图标");
        String[] values = {"default", "minimal", "dark"};
        new YiyunyingDialogBuilder(this)
            .setTitle(tr("桌面图标"))
            .setSingleChoiceItems(labels, checked, (dialog, which) -> {
                boolean changed = AppearanceStyleStore.setIcon(this, values[which]);
                if (changed) {
                    ((YiyunyingApplication) getApplication()).refreshShortcuts();
                }
                dialog.dismiss();
                updateAppearanceLabels();
                showMessage(changed
                    ? "桌面图标已切换，部分桌面可能需要几秒刷新"
                    : "当前安装包不支持这个桌面图标", Snackbar.LENGTH_LONG);
            })
            .setNegativeButton(tr("取消"), null)
            .show();
    }

    private void chooseFont() {
        String current = AppearanceStyleStore.font(this);
        int checked = AppearanceStyleStore.FONT_SANS.equals(current) ? 1
            : (AppearanceStyleStore.FONT_SERIF.equals(current) ? 2
            : (AppearanceStyleStore.FONT_MONOSPACE.equals(current) ? 3 : 0));
        CharSequence[] labels = translatedOptions("系统默认", "现代无衬线", "阅读衬线", "等宽字体");
        String[] values = {
            AppearanceStyleStore.FONT_SYSTEM,
            AppearanceStyleStore.FONT_SANS,
            AppearanceStyleStore.FONT_SERIF,
            AppearanceStyleStore.FONT_MONOSPACE
        };
        new YiyunyingDialogBuilder(this)
            .setTitle(tr("界面字体"))
            .setSingleChoiceItems(labels, checked, (dialog, which) -> {
                AppearanceStyleStore.setFont(this, values[which]);
                dialog.dismiss();
                updateAppearanceLabels();
            })
            .setNegativeButton(tr("取消"), null)
            .show();
    }

    @Override protected void onDestroy() {
        if (request != null) request.cancel();
        binding = null;
        super.onDestroy();
    }
}
