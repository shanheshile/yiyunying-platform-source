package xyz.jjmxg.yiyunying.ui.main;

import android.content.Intent;
import android.content.pm.PackageManager;
import android.os.Build;
import android.os.Bundle;
import android.view.Menu;
import android.view.MenuItem;
import android.view.SubMenu;
import android.view.View;

import androidx.activity.OnBackPressedCallback;
import androidx.appcompat.app.AppCompatActivity;
import androidx.core.view.GravityCompat;
import androidx.core.content.ContextCompat;
import androidx.fragment.app.Fragment;
import androidx.fragment.app.FragmentManager;

import xyz.jjmxg.yiyunying.ui.common.YiyunyingDialogBuilder;
import com.google.android.material.navigation.NavigationView;
import com.google.android.material.snackbar.Snackbar;
import com.google.gson.JsonArray;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;

import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.core.AppAccess;
import xyz.jjmxg.yiyunying.core.RuntimeLanguage;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.data.api.RequestHandle;
import xyz.jjmxg.yiyunying.data.session.SessionManager;
import xyz.jjmxg.yiyunying.databinding.ActivityMainBinding;
import xyz.jjmxg.yiyunying.databinding.NavHeaderBinding;
import xyz.jjmxg.yiyunying.domain.AdminAccessPolicy;
import xyz.jjmxg.yiyunying.domain.AppEdition;
import xyz.jjmxg.yiyunying.domain.Role;
import xyz.jjmxg.yiyunying.domain.module.ModuleSpec;
import xyz.jjmxg.yiyunying.domain.module.ScreenType;
import xyz.jjmxg.yiyunying.ui.auth.LoginActivity;
import xyz.jjmxg.yiyunying.ui.bot.BotFragment;
import xyz.jjmxg.yiyunying.ui.chat.ChatActivity;
import xyz.jjmxg.yiyunying.ui.forum.ForumListActivity;
import xyz.jjmxg.yiyunying.ui.poll.PollActivity;
import xyz.jjmxg.yiyunying.ui.social.SocialDirectoryActivity;
import xyz.jjmxg.yiyunying.ui.social.BlacklistActivity;
import xyz.jjmxg.yiyunying.ui.social.AddFriendActivity;
import xyz.jjmxg.yiyunying.ui.social.FavoriteCenterActivity;
import xyz.jjmxg.yiyunying.ui.common.MainHost;
import xyz.jjmxg.yiyunying.ui.common.BackNavigationHandler;
import xyz.jjmxg.yiyunying.ui.common.LifecycleChecker;
import xyz.jjmxg.yiyunying.ui.common.CrashNotice;
import xyz.jjmxg.yiyunying.ui.common.UiGuard;
import xyz.jjmxg.yiyunying.ui.common.ImageLoader;
import xyz.jjmxg.yiyunying.ui.dashboard.DashboardFragment;
import xyz.jjmxg.yiyunying.ui.document.DocumentsFragment;
import xyz.jjmxg.yiyunying.ui.home.UserShellFragment;
import xyz.jjmxg.yiyunying.ui.home.RelationshipNoticeActivity;
import xyz.jjmxg.yiyunying.ui.module.GenericModuleFragment;
import xyz.jjmxg.yiyunying.ui.notification.NotificationCenterFragment;
import xyz.jjmxg.yiyunying.ui.profile.ProfileFragment;
import xyz.jjmxg.yiyunying.ui.settings.SettingsFragment;
import xyz.jjmxg.yiyunying.ui.settings.UserSettingsActivity;
import xyz.jjmxg.yiyunying.ui.wallet.WalletFragment;
import xyz.jjmxg.yiyunying.ui.upload.UploadFragment;
import xyz.jjmxg.yiyunying.service.MessageNotificationService;

public final class MainActivity extends xyz.jjmxg.yiyunying.ui.common.SystemInsetActivity implements MainHost {
    private static final String EXTRA_OPEN_MODULE = "open_module";
    private static final String EXTRA_NOTIFICATION_CENTER = "notification_center";
    private static final String EXTRA_FOCUS_RECORD_ID = "focus_record_id";
    private static final int APP_ACTION_ID = 91001;
    private static final int LOGOUT_ID = 91002;
    private static final int ALL_FEATURES_ID = 91003;
    private static final int CACHE_ID = 91004;
    private static final long HEADER_PROFILE_REFRESH_INTERVAL_MS = 30_000L;

    private ActivityMainBinding binding;
    private SessionManager session;
    private final Map<Integer, ModuleSpec> navigation = new LinkedHashMap<>();
    private ModuleSpec currentModule;
    private MenuItem appAction;
    private RequestHandle appRequest;
    private RequestHandle headerRequest;
    private RequestHandle headerCacheRequest;
    private NavHeaderBinding headerBinding;
    private String headerProfileSnapshot = "";
    private long lastHeaderProfileLoadAt;
    private boolean leaving;
    private Boolean mainChromeVisible;
    private long lastBackPressedAt;
    private ModuleSpec pendingNavigationModule;
    private boolean pendingNavigationRoot;
    private final FragmentManager.FragmentLifecycleCallbacks chromeLifecycleCallbacks =
        new FragmentManager.FragmentLifecycleCallbacks() {
            @Override public void onFragmentResumed(FragmentManager manager, Fragment fragment) {
                if (binding == null || fragment.getId() != R.id.contentContainer) return;
                Fragment visible = manager.findFragmentById(R.id.contentContainer);
                if (visible == fragment) syncMainChromeWithVisibleFragment();
            }
        };

    @Override protected boolean usePlatformDecorInsets() {
        // Android 15/16 vendor builds do not consistently honour decorFitsSystemWindows.
        // Pad the single drawer root ourselves so both the user's own home header and the
        // main toolbar used by Notes receive exactly one stable status-bar/cutout inset.
        return false;
    }

    @Override protected boolean includeImeInsetsInRootPadding() {
        return false;
    }

    public static Intent moduleIntent(android.content.Context context, String moduleId) {
        return new Intent(context, MainActivity.class).putExtra(EXTRA_OPEN_MODULE, moduleId)
            .addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP | Intent.FLAG_ACTIVITY_SINGLE_TOP);
    }

    public static Intent moduleIntent(android.content.Context context, String moduleId, long focusRecordId) {
        Intent intent = moduleIntent(context, moduleId);
        if (focusRecordId > 0) intent.putExtra(EXTRA_FOCUS_RECORD_ID, focusRecordId);
        return intent;
    }

    public static Intent notificationIntent(android.content.Context context, String center) {
        return moduleIntent(context, "notifications")
            .putExtra(EXTRA_NOTIFICATION_CENTER, center == null ? "social" : center);
    }

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        session = AppAccess.from(this).session();
        if (!session.isAuthenticated()) {
            showLogin();
            return;
        }
        ((xyz.jjmxg.yiyunying.YiyunyingApplication) getApplication()).refreshShortcuts();
        binding = ActivityMainBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());
        getSupportFragmentManager().registerFragmentLifecycleCallbacks(chromeLifecycleCallbacks, false);
        CrashNotice.showPending(this);
        binding.toolbar.setNavigationOnClickListener(view -> {
            if (getSupportFragmentManager().getBackStackEntryCount() > 0) {
                getOnBackPressedDispatcher().onBackPressed();
            } else {
                binding.drawerLayout.openDrawer(GravityCompat.START);
            }
        });
        buildHeader();
        buildNavigation();
        buildToolbarActions();
        getOnBackPressedDispatcher().addCallback(this, new OnBackPressedCallback(true) {
            @Override public void handleOnBackPressed() {
                if (binding.drawerLayout.isDrawerOpen(GravityCompat.START)) {
                    binding.drawerLayout.closeDrawer(GravityCompat.START);
                    return;
                }
                if (getSupportFragmentManager().getBackStackEntryCount() > 0) {
                    getSupportFragmentManager().popBackStack();
                    return;
                }
                Fragment current = getSupportFragmentManager().findFragmentById(R.id.contentContainer);
                if (current instanceof BackNavigationHandler
                    && ((BackNavigationHandler) current).onBackRequested()) return;
                long now = android.os.SystemClock.elapsedRealtime();
                if (now - lastBackPressedAt <= 2000L) {
                    moveTaskToBack(true);
                } else {
                    lastBackPressedAt = now;
                    Snackbar.make(binding.contentContainer, "再按一次返回桌面，消息服务会继续运行",
                        Snackbar.LENGTH_SHORT).show();
                }
            }
        });
        getSupportFragmentManager().addOnBackStackChangedListener(this::updateNavigationIcon);
        if (session.role() == Role.USER) startMessageNotifications();
        if (savedInstanceState == null) {
            LifecycleChecker.check(this, binding.getRoot(), this::openInitialPage);
        } else {
            binding.getRoot().post(() -> {
                syncMainChromeWithVisibleFragment();
                updateNavigationIcon();
            });
        }
    }

    private void startMessageNotifications() {
        if (Build.VERSION.SDK_INT >= 33
            && ContextCompat.checkSelfPermission(this, android.Manifest.permission.POST_NOTIFICATIONS)
                != PackageManager.PERMISSION_GRANTED) {
            requestPermissions(new String[]{android.Manifest.permission.POST_NOTIFICATIONS}, 4101);
        }
        MessageNotificationService.start(this);
    }

    private void buildHeader() {
        headerBinding = NavHeaderBinding.bind(binding.navigationView.getHeaderView(0));
        JsonObject fallback = new JsonObject();
        fallback.addProperty("account", session.account());
        renderHeader(fallback);
        headerBinding.getRoot().setContentDescription("查看当前账号资料");
        headerBinding.getRoot().setOnClickListener(view -> {
            binding.drawerLayout.closeDrawer(GravityCompat.START);
            openModule("profile");
        });
        loadHeaderProfile(true);
    }

    private void loadHeaderProfile(boolean force) {
        if (headerBinding == null || leaving || !session.isAuthenticated()) return;
        long now = android.os.SystemClock.elapsedRealtime();
        if (!force && now - lastHeaderProfileLoadAt < HEADER_PROFILE_REFRESH_INTERVAL_MS) return;
        lastHeaderProfileLoadAt = now;
        if (headerRequest != null) headerRequest.cancel();
        if (headerCacheRequest != null) headerCacheRequest.cancel();
        String path = session.role().mePath();
        LinkedHashMap<String, String> query = new LinkedHashMap<>();
        headerCacheRequest = AppAccess.from(this).repository().getCached(path, query, cached -> {
            headerCacheRequest = null;
            if (headerBinding == null || leaving || !cached.isSuccessful()) return;
            renderHeaderIfChanged(Jsons.object(cached.dataObject(), session.role().wireName()));
        });
        headerRequest = AppAccess.from(this).repository().get(path, query, result -> {
                headerRequest = null;
                if (headerBinding == null || !result.isSuccessful()) return;
                JsonObject profile = Jsons.object(result.dataObject(), session.role().wireName());
                renderHeaderIfChanged(profile);
            });
    }

    private void renderHeaderIfChanged(JsonObject profile) {
        String snapshot = profile == null ? "" : profile.toString();
        if (snapshot.equals(headerProfileSnapshot)) return;
        headerProfileSnapshot = snapshot;
        renderHeader(profile == null ? new JsonObject() : profile);
    }

    private void renderHeader(JsonObject profile) {
        if (headerBinding == null) return;
        String account = firstValue(Jsons.string(profile, "account"), session.account());
        String nickname = Jsons.string(profile, "nickname");
        String uid = firstValue(Jsons.string(profile, "uid"), Jsons.string(profile, "user_uid"), "--");
        String identity;
        String level;
        String privilege;
        if (session.role() == Role.PLATFORM) {
            identity = session.actorLevel() == 1 ? "平台总控" : "授权平台";
            level = firstValue(Jsons.string(profile, "level"), String.valueOf(Math.max(1, session.actorLevel())));
            privilege = firstValue(Jsons.string(profile, "membership_level"), "平台管理权限");
        } else if (session.role() == Role.ADMIN) {
            identity = "管理员";
            level = firstValue(Jsons.string(profile, "membership_level"), "普通");
            privilege = session.isAdminBillingOnly() ? "账号权益受限"
                : firstValue(Jsons.string(profile, "membership_level"), "应用管理权限");
        } else {
            identity = firstValue(Jsons.string(profile, "role_label"),
                Jsons.string(profile, "identity_label"), Jsons.string(profile, "moderator_title"), "普通用户");
            level = firstValue(Jsons.string(profile, "level_code"), "普通");
            String membership = firstValue(Jsons.string(profile, "vip_level"),
                Jsons.string(profile, "membership_level"));
            privilege = membership.isEmpty() ? "普通账号" : membership;
        }
        long experience = Math.max(0L, Math.max(Jsons.longValue(profile, "experience"),
            Jsons.longValue(profile, "exp")));
        long target = Math.max(Jsons.longValue(profile, "next_level_experience"),
            Jsons.longValue(profile, "level_experience_required"));
        if (target <= experience) target = Math.max(100L, ((experience / 100L) + 1L) * 100L);
        int percent = (int) Math.max(0L, Math.min(100L, experience * 100L / Math.max(1L, target)));

        RuntimeLanguage.setDynamicText(headerBinding.accountName, account);
        headerBinding.identityBadge.setText(identity);
        headerBinding.uidText.setText("UID：" + uid);
        if (nickname.isEmpty()) {
            headerBinding.roleName.setText(RuntimeLanguage.translate(this, session.role().displayName()));
        } else {
            RuntimeLanguage.setDynamicText(headerBinding.roleName,
                RuntimeLanguage.translate(this, "昵称：") + nickname);
        }
        headerBinding.levelText.setText("等级：" + level);
        headerBinding.experienceProgress.setProgressCompat(percent, true);
        headerBinding.experienceText.setText("经验 " + experience + " / " + target);
        headerBinding.privilegeText.setText("特权：" + privilege);
        String avatarUrl = ImageLoader.get().absoluteUrl(this, Jsons.string(profile, "avatar"));
        ImageLoader.get().load(avatarUrl, headerBinding.avatar, R.drawable.ic_person);
    }

    private String firstValue(String... values) {
        if (values == null) return "";
        for (String value : values) if (value != null && !value.trim().isEmpty()) return value.trim();
        return "";
    }

    private void buildNavigation() {
        Menu menu = binding.navigationView.getMenu();
        menu.clear();
        navigation.clear();
        String group = null;
        SubMenu subMenu = null;
        for (ModuleSpec module : AppAccess.from(this).modules().forRole(session.role())) {
            if (!canOpen(module) || !isCoreNavigation(module.id())) continue;
            if (!module.group().equals(group)) {
                group = module.group();
                subMenu = menu.addSubMenu(group);
            }
            int id = View.generateViewId();
            MenuItem item = subMenu.add(Menu.NONE, id, Menu.NONE, module.title());
            item.setIcon(iconFor(module));
            item.setCheckable(true);
            navigation.put(id, module);
        }
        menu.add(Menu.NONE, ALL_FEATURES_ID, Menu.NONE, "全部功能").setIcon(R.drawable.ic_apps);
        menu.add(Menu.NONE, LOGOUT_ID, Menu.NONE, R.string.logout).setIcon(R.drawable.ic_person);
        binding.navigationView.setNavigationItemSelectedListener(this::onNavigationItem);
    }

    private void buildToolbarActions() {
        MenuItem settings = binding.toolbar.getMenu().add(Menu.NONE, CACHE_ID, Menu.NONE, "设置");
        settings.setIcon(R.drawable.ic_settings);
        settings.setShowAsAction(MenuItem.SHOW_AS_ACTION_NEVER);
        settings.setOnMenuItemClickListener(item -> {
            UserSettingsActivity.open(this);
            return true;
        });
        if (session.role() != Role.ADMIN || session.isAdminBillingOnly()) return;
        appAction = binding.toolbar.getMenu().add(Menu.NONE, APP_ACTION_ID, Menu.NONE, appTitle());
        appAction.setIcon(R.drawable.ic_apps);
        appAction.setShowAsAction(MenuItem.SHOW_AS_ACTION_IF_ROOM);
        appAction.setOnMenuItemClickListener(item -> {
            requestAppSelection();
            return true;
        });
    }

    private boolean onNavigationItem(MenuItem item) {
        if (item.getItemId() == LOGOUT_ID) {
            logout();
            return true;
        }
        if (item.getItemId() == ALL_FEATURES_ID) {
            openFeatureDirectory();
            item.setChecked(true);
            binding.drawerLayout.closeDrawer(GravityCompat.START);
            return true;
        }
        ModuleSpec module = navigation.get(item.getItemId());
        if (module == null) return false;
        open(module);
        item.setChecked(true);
        binding.drawerLayout.closeDrawer(GravityCompat.START);
        return true;
    }

    private boolean isCoreNavigation(String id) {
        if (session.role() == Role.PLATFORM) {
            return "dashboard".equals(id) || "profile".equals(id);
        }
        if (session.role() == Role.ADMIN) {
            return "dashboard".equals(id) || "apps".equals(id) || "profile".equals(id);
        }
        return "home".equals(id) || "profile".equals(id);
    }

    private void openFeatureDirectory() {
        setPageTitle("全部功能");
        setMainChromeVisible(true);
        getSupportFragmentManager().beginTransaction()
            .setReorderingAllowed(true)
            .replace(R.id.contentContainer, FeatureDirectoryFragment.newInstance(), "feature_directory")
            .addToBackStack("feature_directory")
            .commit();
        binding.contentContainer.post(this::updateNavigationIcon);
    }

    private void openInitialPage() {
        if (session.isAdminBillingOnly()) {
            openRootById("entitlement");
            showAccessRestriction();
        } else if (session.role() == Role.ADMIN && session.selectedAppId() == 0) {
            openRootById("apps");
            loadApps(true);
        } else {
            openRootById(session.role() == Role.USER ? "home" : "dashboard");
        }
        String requested = getIntent().getStringExtra(EXTRA_OPEN_MODULE);
        if (requested != null && !requested.isEmpty()) {
            binding.contentContainer.post(() -> openModule(requested));
            getIntent().removeExtra(EXTRA_OPEN_MODULE);
        }
    }

    @Override protected void onNewIntent(Intent intent) {
        super.onNewIntent(intent);
        setIntent(intent);
        String requested = intent.getStringExtra(EXTRA_OPEN_MODULE);
        if (requested != null && !requested.isEmpty()) openModule(requested);
    }

    @Override
    public void openModule(String moduleId) {
        if (session.role() == Role.USER && isLegacyFavoriteModule(moduleId)) {
            FavoriteCenterActivity.open(this);
            return;
        }
        ModuleSpec module = AppAccess.from(this).modules().find(session.role(), moduleId);
        if (module == null || !canOpen(module)) {
            Snackbar.make(binding.contentContainer, "当前版本或账号未开放该功能", Snackbar.LENGTH_LONG).show();
            return;
        }
        open(module);
    }

    private static boolean isLegacyFavoriteModule(String moduleId) {
        return "favorite_messages".equals(moduleId)
            || "forum_favorites".equals(moduleId)
            || "favorite_resources".equals(moduleId)
            || "favorite_store_apps".equals(moduleId);
    }

    private void open(ModuleSpec module) {
        UiGuard.run(binding.contentContainer, "打开模块/" + module.id(), () -> openGuarded(module, false));
    }

    private void openRootById(String id) {
        ModuleSpec module = AppAccess.from(this).modules().find(session.role(), id);
        if (module != null) {
            UiGuard.run(binding.contentContainer, "打开根页面/" + module.id(), () -> openGuarded(module, true));
        }
    }

    private void openGuarded(ModuleSpec module, boolean root) {
        if (isFinishing() || (Build.VERSION.SDK_INT >= 17 && isDestroyed())) return;
        if (getSupportFragmentManager().isStateSaved()) {
            pendingNavigationModule = module;
            pendingNavigationRoot = root;
            return;
        }
        pendingNavigationModule = null;
        if (!canOpen(module)) {
            Snackbar.make(binding.contentContainer, session.adminAccessReason(), Snackbar.LENGTH_LONG).show();
            return;
        }
        if (session.role() == Role.USER && "service".equals(module.id())) {
            ChatActivity.openUserService(this);
            return;
        }
        if ("forum_posts".equals(module.id()) && session.role() == Role.USER) {
            ForumListActivity.open(this);
            return;
        }
        if (session.role() == Role.USER && ("polls".equals(module.id()) || "votes".equals(module.id()))) {
            PollActivity.open(this);
            return;
        }
        if (session.role() == Role.USER && "friends".equals(module.id())) {
            SocialDirectoryActivity.openFriends(this);
            return;
        }
        if (session.role() == Role.USER && "user_search".equals(module.id())) {
            AddFriendActivity.open(this);
            return;
        }
        if (session.role() == Role.USER && "friend_requests".equals(module.id())) {
            RelationshipNoticeActivity.open(this, "friend_incoming", "好友申请");
            return;
        }
        if (session.role() == Role.USER && "chat_room_invitations".equals(module.id())) {
            RelationshipNoticeActivity.open(this, "group_invitation", "群聊邀请");
            return;
        }
        if (session.role() == Role.USER && "chat_rooms".equals(module.id())) {
            SocialDirectoryActivity.openRooms(this);
            return;
        }
        if (session.role() == Role.USER && "blacklist".equals(module.id())) {
            BlacklistActivity.open(this);
            return;
        }
        if (session.role() == Role.USER && module.screenType() == ScreenType.SETTINGS) {
            UserSettingsActivity.open(this);
            return;
        }
        if (module.requiresApp() && session.selectedAppId() == 0) {
            Snackbar.make(binding.contentContainer, "请先选择应用", Snackbar.LENGTH_LONG).show();
            requestAppSelection();
            return;
        }
        if ("forum_posts".equals(module.id()) && session.role() == Role.ADMIN) {
            ForumListActivity.openForApp(this, session.selectedAppId(), session.selectedAppName());
            return;
        }
        currentModule = module;
        setPageTitle(module.title());
        setMainChromeVisible(module.screenType() != ScreenType.USER_HOME);
        Fragment fragment;
        long focusRecordId = getIntent().getLongExtra(EXTRA_FOCUS_RECORD_ID, 0L);
        switch (module.screenType()) {
            case DASHBOARD:
                fragment = session.role() == Role.USER
                    ? DashboardFragment.newInstance(module.id())
                    : ManagementShellFragment.newInstance();
                break;
            case USER_HOME: fragment = UserShellFragment.newInstance(); break;
            case DOCUMENTS: fragment = DocumentsFragment.newInstance(); break;
            case PROFILE: fragment = ProfileFragment.newInstance(); break;
            case WALLET: fragment = WalletFragment.newInstance(module.id()); break;
            case SETTINGS: fragment = SettingsFragment.newInstance(module.id()); break;
            case BOT: fragment = BotFragment.newInstance(); break;
            case UPLOAD: fragment = UploadFragment.newInstance(); break;
            case NOTIFICATIONS:
                fragment = NotificationCenterFragment.newInstance(
                    getIntent().getStringExtra(EXTRA_NOTIFICATION_CENTER));
                getIntent().removeExtra(EXTRA_NOTIFICATION_CENTER);
                break;
            default: fragment = GenericModuleFragment.newInstance(module.id(), focusRecordId);
        }
        if (focusRecordId > 0) getIntent().removeExtra(EXTRA_FOCUS_RECORD_ID);
        androidx.fragment.app.FragmentManager fragmentManager = getSupportFragmentManager();
        if (root && fragmentManager.getBackStackEntryCount() > 0) {
            fragmentManager.popBackStack(null,
                androidx.fragment.app.FragmentManager.POP_BACK_STACK_INCLUSIVE);
        }
        androidx.fragment.app.FragmentTransaction transaction = fragmentManager.beginTransaction()
            .setReorderingAllowed(true)
            .replace(R.id.contentContainer, fragment, module.id());
        if (!root) transaction.addToBackStack(module.id());
        transaction.commit();
        binding.contentContainer.post(this::updateNavigationIcon);
    }

    @Override public void setPageTitle(String title) { binding.toolbar.setTitle(title); }

    private void syncMainChromeWithVisibleFragment() {
        if (binding == null) return;
        Fragment visible = getSupportFragmentManager().findFragmentById(R.id.contentContainer);
        if (visible == null) return;
        setMainChromeVisible(!(visible instanceof UserShellFragment));
    }

    @Override
    public void setMainChromeVisible(boolean visible) {
        if (binding == null) return;
        mainChromeVisible = visible;
        binding.appBar.clearAnimation();
        binding.appBar.setVisibility(visible ? View.VISIBLE : View.GONE);
        binding.appBar.setTranslationY(0f);
        binding.toolbar.setTranslationY(0f);
        binding.contentContainer.setTranslationY(0f);
        if (!visible && binding.drawerLayout.isDrawerOpen(GravityCompat.START)) {
            binding.drawerLayout.closeDrawer(GravityCompat.START);
        }
        binding.drawerLayout.setDrawerLockMode(visible
            ? androidx.drawerlayout.widget.DrawerLayout.LOCK_MODE_UNLOCKED
            : androidx.drawerlayout.widget.DrawerLayout.LOCK_MODE_LOCKED_CLOSED);
    }

    @Override protected void onResume() {
        super.onResume();
        if (headerBinding != null) loadHeaderProfile(false);
    }

    @Override protected void onPostResume() {
        super.onPostResume();
        if (binding == null) return;
        syncMainChromeWithVisibleFragment();
        if (pendingNavigationModule == null || getSupportFragmentManager().isStateSaved()) return;
        ModuleSpec module = pendingNavigationModule;
        boolean root = pendingNavigationRoot;
        pendingNavigationModule = null;
        binding.contentContainer.post(() -> {
            if (binding != null && !isFinishing()
                && !getSupportFragmentManager().isStateSaved()) openGuarded(module, root);
        });
    }

    @Override
    public void openNavigationDrawer() {
        binding.drawerLayout.setDrawerLockMode(androidx.drawerlayout.widget.DrawerLayout.LOCK_MODE_UNLOCKED);
        binding.drawerLayout.openDrawer(GravityCompat.START);
    }

    private void updateNavigationIcon() {
        if (binding == null) return;
        boolean back = getSupportFragmentManager().getBackStackEntryCount() > 0;
        binding.toolbar.setNavigationIcon(back ? R.drawable.ic_back : R.drawable.ic_menu);
        binding.toolbar.setNavigationContentDescription(back ? "返回上一页" : "打开功能菜单");
    }

    @Override
    public void onAuthenticationExpired() {
        if (leaving) return;
        leaving = true;
        session.clearAuthentication();
        MessageNotificationService.stop(this);
        Snackbar.make(binding.contentContainer, R.string.session_expired, Snackbar.LENGTH_LONG)
            .addCallback(new Snackbar.Callback() {
                @Override public void onDismissed(Snackbar transientBottomBar, int event) { showLogin(); }
            }).show();
    }

    @Override
    public void requestAppSelection() {
        if (session.role() == Role.ADMIN) loadApps(false);
    }

    @Override
    public void onLogoutRequested() {
        logout();
    }

    @Override
    public void onAppSelectionChanged() {
        if (appAction != null) appAction.setTitle(appTitle());
        if (currentModule != null && currentModule.requiresApp()) open(currentModule);
    }

    @Override
    public void onAdminAccessStateChanged() {
        recreate();
    }

    @Override
    public void refreshProfileChrome() {
        lastHeaderProfileLoadAt = 0L;
        loadHeaderProfile(true);
    }

    private boolean canOpen(ModuleSpec module) {
        if (session.role() == Role.PLATFORM
            && !AppEdition.canOpenPlatformModule(session.actorLevel(), module.id())) return false;
        return session.role() != Role.ADMIN || AdminAccessPolicy.canOpen(session.adminAccessMode(), module.id());
    }

    private void showAccessRestriction() {
        String reason = session.adminAccessReason();
        new YiyunyingDialogBuilder(this)
            .setTitle("当前仅可使用权益功能")
            .setMessage(reason.isEmpty() ? "请续费或联系所属平台恢复完整权限。" : reason)
            .setPositiveButton("查看权益", null)
            .show();
    }

    private void loadApps(boolean autoSelect) {
        if (appRequest != null) appRequest.cancel();
        Map<String, String> query = new LinkedHashMap<>();
        query.put("page", "1");
        query.put("limit", "100");
        appRequest = AppAccess.from(this).repository().get("/api/admin/apps", query, result -> {
            if (result.isAuthenticationFailure()) {
                onAuthenticationExpired();
                return;
            }
            if (!result.isSuccessful()) {
                Snackbar.make(binding.contentContainer, result.message().isEmpty() ? "应用加载失败" : result.message(), Snackbar.LENGTH_LONG).show();
                return;
            }
            List<JsonObject> apps = objects(result.items());
            if (apps.isEmpty()) {
                if (!autoSelect) Snackbar.make(binding.contentContainer, "暂无应用，请先创建", Snackbar.LENGTH_LONG).show();
                return;
            }
            if (autoSelect) {
                selectApp(apps.get(0));
                openRootById("dashboard");
            } else {
                showAppDialog(apps);
            }
        });
    }

    private void showAppDialog(List<JsonObject> apps) {
        String[] labels = new String[apps.size()];
        int checked = -1;
        for (int index = 0; index < apps.size(); index++) {
            JsonObject app = apps.get(index);
            labels[index] = Jsons.string(app, "name") + "\n" + Jsons.string(app, "app_key");
            if (Jsons.longValue(app, "id") == session.selectedAppId()) checked = index;
        }
        final int current = checked;
        new YiyunyingDialogBuilder(this)
            .setTitle(R.string.select_app)
            .setSingleChoiceItems(labels, checked, (dialog, which) -> {
                selectApp(apps.get(which));
                dialog.dismiss();
                if (current != which) onAppSelectionChanged();
            })
            .setNegativeButton(R.string.cancel, null)
            .show();
    }

    private void selectApp(JsonObject app) {
        session.selectApp(Jsons.longValue(app, "id"), Jsons.string(app, "name"), Jsons.string(app, "app_key"));
        if (appAction != null) appAction.setTitle(appTitle());
    }

    private void logout() {
        MessageNotificationService.stop(this);
        AppAccess.from(this).auth().logout(result -> showLogin());
    }

    private void showLogin() {
        ((xyz.jjmxg.yiyunying.YiyunyingApplication) getApplication()).refreshShortcuts();
        startActivity(new Intent(this, LoginActivity.class)
            .putExtra(LoginActivity.EXTRA_FORCE_LOGIN, true)
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TASK));
        finish();
    }

    private String appTitle() {
        String name = session.selectedAppName();
        return name.isEmpty() ? getString(R.string.select_app) : name;
    }

    private int iconFor(ModuleSpec module) {
        if (module.screenType() == ScreenType.DASHBOARD || "home".equals(module.id())) return R.drawable.ic_dashboard;
        if (module.id().contains("app") || module.id().contains("domain")) return R.drawable.ic_apps;
        if (module.id().contains("user") || module.id().contains("operator") || module.id().contains("admin")) return R.drawable.ic_users;
        if (module.id().contains("document") || module.id().contains("notice") || module.id().contains("version") || module.id().contains("banner")) return R.drawable.ic_document;
        if (module.id().contains("forum") || module.id().contains("resource") || module.id().contains("store") || module.id().contains("report")) return R.drawable.ic_forum;
        if (module.id().contains("message") || module.id().contains("chat") || module.id().contains("service") || module.id().contains("friend")) return R.drawable.ic_chat;
        if (module.id().contains("card") || module.id().contains("order") || module.id().contains("exchange") || module.id().contains("integral") || module.id().contains("wallet") || module.id().contains("lottery") || module.id().contains("vote")) return R.drawable.ic_wallet;
        if (module.id().contains("file") || module.id().contains("upload") || module.id().contains("feedback") || module.id().contains("bot")) return R.drawable.ic_file;
        if (module.id().contains("log") || module.id().contains("stat")) return R.drawable.ic_stats;
        if (module.screenType() == ScreenType.SETTINGS) return R.drawable.ic_settings;
        if (module.screenType() == ScreenType.PROFILE) return R.drawable.ic_person;
        return R.drawable.ic_content;
    }

    private static List<JsonObject> objects(JsonArray array) {
        List<JsonObject> result = new ArrayList<>();
        for (JsonElement element : array) if (element.isJsonObject()) result.add(element.getAsJsonObject());
        return result;
    }

    @Override protected void onDestroy() {
        getSupportFragmentManager().unregisterFragmentLifecycleCallbacks(chromeLifecycleCallbacks);
        if (appRequest != null) appRequest.cancel();
        if (headerRequest != null) headerRequest.cancel();
        if (headerCacheRequest != null) headerCacheRequest.cancel();
        headerBinding = null;
        super.onDestroy();
    }
}
