package xyz.jjmxg.yiyunying.ui.main;

import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertNotNull;
import static org.junit.Assert.assertTrue;
import static org.robolectric.Shadows.shadowOf;

import android.os.Looper;
import android.os.Bundle;
import android.os.Handler;
import android.view.View;
import android.view.ViewGroup;

import androidx.fragment.app.Fragment;
import androidx.test.core.app.ApplicationProvider;

import org.junit.After;
import org.junit.Before;
import org.junit.Test;
import org.junit.runner.RunWith;
import org.robolectric.Robolectric;
import org.robolectric.RobolectricTestRunner;
import org.robolectric.android.controller.ActivityController;
import org.robolectric.annotation.Config;

import java.io.IOException;
import java.lang.reflect.Field;
import java.util.List;
import java.util.concurrent.CopyOnWriteArrayList;
import java.util.concurrent.atomic.AtomicInteger;

import okhttp3.HttpUrl;
import okhttp3.OkHttpClient;
import okhttp3.Request;
import okhttp3.mockwebserver.MockResponse;
import okhttp3.mockwebserver.MockWebServer;
import okhttp3.mockwebserver.Dispatcher;
import okhttp3.mockwebserver.RecordedRequest;
import xyz.jjmxg.yiyunying.BuildConfig;
import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.YiyunyingApplication;
import xyz.jjmxg.yiyunying.core.AppAccess;
import xyz.jjmxg.yiyunying.core.AppContainer;
import xyz.jjmxg.yiyunying.data.api.ApiClient;
import xyz.jjmxg.yiyunying.data.repository.YiyunyingRepository;
import xyz.jjmxg.yiyunying.data.session.SessionManager;
import xyz.jjmxg.yiyunying.domain.AppEdition;
import xyz.jjmxg.yiyunying.domain.Role;

@RunWith(RobolectricTestRunner.class)
@Config(sdk = 34, application = YiyunyingApplication.class)
public final class MainActivityRuntimeTest {
    private MockWebServer server;
    private SessionManager session;
    private Object originalRepository;
    private final List<String> buildIdentityRequests = new CopyOnWriteArrayList<>();
    private final AtomicInteger meRequestCount = new AtomicInteger();

    @Before
    public void setUp() throws IOException {
        buildIdentityRequests.clear();
        meRequestCount.set(0);
        server = new MockWebServer();
        server.setDispatcher(new Dispatcher() {
            @Override
            public MockResponse dispatch(RecordedRequest request) {
                String path = request.getPath() == null ? "" : request.getPath();
                if (path.startsWith(AppEdition.role().mePath())) {
                    meRequestCount.incrementAndGet();
                    if (!"Bearer runtime-token".equals(request.getHeader("Authorization"))) {
                        return rejected(401, "测试会话未携带实时 Token");
                    }
                    if (AppEdition.role() == Role.ADMIN
                        && (request.getRequestUrl() == null
                        || !BuildConfig.DEFAULT_APP_KEY.equals(
                            request.getRequestUrl().queryParameter("app_key")))) {
                        return rejected(403, "管理员实时身份未携带构建应用 KEY");
                    }
                    if (AppEdition.role() == Role.USER
                        && !BuildConfig.DEFAULT_APP_KEY.equals(request.getHeader("X-App-Key"))) {
                        return rejected(403, "用户实时身份未携带构建应用 KEY");
                    }
                    return response(meResponse());
                }
                if (path.startsWith("/api/public/lifecycle")) return response(lifecycleResponse());
                if (path.startsWith("/api/public/bootstrap")) return response(initialResponse());
                if (path.contains("/statistics") || path.startsWith("/api/platform/dashboard")) {
                    return response(initialResponse());
                }
                return response("{\"code\":1,\"msg\":\"操作成功\",\"data\":{"
                    + "\"items\":[],\"pagination\":{\"page\":1,\"total_pages\":0,\"total\":0},"
                    + "\"summary\":{},\"finance\":{},\"api\":{},\"daily\":[]}}");
            }
        });
        server.start();
        YiyunyingApplication application = ApplicationProvider.getApplicationContext();
        session = AppAccess.from(application).session();
        session.clearAuthentication();
        session.configureConnection(
            BuildConfig.DEFAULT_API_BASE_URL,
            BuildConfig.DEFAULT_APP_KEY,
            BuildConfig.DEFAULT_PLATFORM_KEY
        );
        Role role = AppEdition.role();
        int actorLevel = role == Role.PLATFORM ? AppEdition.requiredPlatformLevel() : role == Role.ADMIN ? 3 : 4;
        session.saveAuthenticated(role, AppEdition.defaultAccount(), "runtime-token", "refresh-token",
            "2099-01-01 00:00:00", "2099-02-01 00:00:00", BuildConfig.DEFAULT_APP_KEY, 1L, actorLevel);
        if (role == Role.ADMIN) session.selectApp(1L, "演示应用", BuildConfig.DEFAULT_APP_KEY);
        installBuildIdentityTransport(application);
    }

    @After
    public void tearDown() throws IOException {
        restoreRepository();
        if (session != null) session.clearAuthentication();
        if (server != null) server.shutdown();
    }

    @Test
    public void authenticatedEditionStartsItsInitialScreenWithoutCrashing() throws Exception {
        try (ActivityController<MainActivity> controller = Robolectric.buildActivity(MainActivity.class).setup()) {
            MainActivity activity = controller.get();
            awaitFragment(activity);
            assertFalse(activity.isFinishing());
            assertFalse(activity.isDestroyed());
            Fragment fragment = activity.getSupportFragmentManager().findFragmentById(R.id.contentContainer);
            assertNotNull(fragment);
            assertTrue(fragment.isAdded());
            assertTrue("MainActivity 必须先请求当前角色的实时 /me", meRequestCount.get() > 0);
            assertTrue("实时 /me 必须从 BuildConfig 身份构造原始请求", sawBuildIdentityMeRequest());
        }
    }

    @Test
    public void everyEditionModuleCanCreateItsScreenWithoutTerminatingTheApp() throws Exception {
        try (ActivityController<MainActivity> controller = Robolectric.buildActivity(MainActivity.class).setup()) {
            MainActivity activity = controller.get();
            awaitFragment(activity);
            for (xyz.jjmxg.yiyunying.domain.module.ModuleSpec module
                : AppAccess.from(activity).modules().forRole(AppEdition.role())) {
                activity.openModule(module.id());
                shadowOf(Looper.getMainLooper()).idle();
                activity.getSupportFragmentManager().executePendingTransactions();
                assertFalse("模块打开后 Activity 不应退出：" + module.id(), activity.isFinishing());
                assertFalse("模块打开后 Activity 不应销毁：" + module.id(), activity.isDestroyed());
            }
        }
    }

    @Test
    public void navigationRequestedAfterStateSaveIsReplayedAfterResume() throws Exception {
        try (ActivityController<MainActivity> controller = Robolectric.buildActivity(MainActivity.class).setup()) {
            MainActivity activity = controller.get();
            awaitFragment(activity);
            xyz.jjmxg.yiyunying.domain.module.ModuleSpec module =
                AppAccess.from(activity).modules().forRole(AppEdition.role()).get(0);
            controller.pause().saveInstanceState(new Bundle());
            assertTrue(activity.getSupportFragmentManager().isStateSaved());

            activity.openModule(module.id());
            shadowOf(Looper.getMainLooper()).idle();
            assertFalse(activity.isFinishing());

            controller.resume().postResume();
            shadowOf(Looper.getMainLooper()).idle();
            activity.getSupportFragmentManager().executePendingTransactions();
            assertFalse(activity.getSupportFragmentManager().isStateSaved());
            assertFalse(activity.isFinishing());
        }
    }

    @Test
    public void userHomeKeepsItsTopActionsVisibleAndNotesRestoreMainToolbar() throws Exception {
        if (AppEdition.role() != Role.USER) return;
        try (ActivityController<MainActivity> controller = Robolectric.buildActivity(MainActivity.class).setup()) {
            MainActivity activity = controller.get();
            awaitFragment(activity);

            View topActions = activity.findViewById(R.id.topActions);
            assertNotNull(topActions);
            assertTrue(topActions.getVisibility() == View.VISIBLE);
            assertTrue(topActions.getParent() instanceof ViewGroup);
            assertTrue(((ViewGroup) topActions.getParent()).indexOfChild(topActions) == 0);
            assertTrue(activity.findViewById(R.id.appBar).getVisibility() == View.GONE);

            activity.openModule("documents");
            shadowOf(Looper.getMainLooper()).idle();
            activity.getSupportFragmentManager().executePendingTransactions();
            assertTrue(activity.findViewById(R.id.appBar).getVisibility() == View.VISIBLE);
            assertNotNull(activity.findViewById(R.id.searchInput));
        }
    }

    private void awaitFragment(MainActivity activity) throws InterruptedException {
        long deadline = System.currentTimeMillis() + 5000L;
        while (System.currentTimeMillis() < deadline) {
            shadowOf(Looper.getMainLooper()).idle();
            Fragment fragment = activity.getSupportFragmentManager().findFragmentById(R.id.contentContainer);
            if (fragment != null && fragment.isAdded()) return;
            Thread.sleep(25L);
        }
    }

    private MockResponse response(String json) {
        return new MockResponse().setResponseCode(200)
            .setHeader("Content-Type", "application/json; charset=utf-8")
            .setBody(json);
    }

    private void installBuildIdentityTransport(YiyunyingApplication application) throws IOException {
        AppContainer container = application.container();
        try {
            Field repositoryField = AppContainer.class.getDeclaredField("repository");
            repositoryField.setAccessible(true);
            originalRepository = repositoryField.get(container);
            HttpUrl buildBase = HttpUrl.get(BuildConfig.DEFAULT_API_BASE_URL);
            OkHttpClient client = new OkHttpClient.Builder()
                .addInterceptor(chain -> {
                    Request request = chain.request();
                    HttpUrl original = request.url();
                    if (!sameOrigin(buildBase, original)) {
                        throw new IOException("测试请求偏离 BuildConfig 服务器身份：" + original);
                    }
                    buildIdentityRequests.add(original.toString());
                    HttpUrl.Builder forwarded = server.url(original.encodedPath()).newBuilder();
                    forwarded.encodedQuery(original.encodedQuery());
                    return chain.proceed(request.newBuilder().url(forwarded.build()).build());
                })
                .build();
            Handler mainHandler = new Handler(Looper.getMainLooper());
            ApiClient apiClient = new ApiClient(session, client, command -> mainHandler.post(command));
            repositoryField.set(container, new YiyunyingRepository(apiClient));
        } catch (ReflectiveOperationException exception) {
            throw new IOException("无法安装 BuildConfig 身份测试传输层", exception);
        }
    }

    private void restoreRepository() throws IOException {
        if (originalRepository == null) return;
        YiyunyingApplication application = ApplicationProvider.getApplicationContext();
        try {
            Field repositoryField = AppContainer.class.getDeclaredField("repository");
            repositoryField.setAccessible(true);
            repositoryField.set(application.container(), originalRepository);
            originalRepository = null;
        } catch (ReflectiveOperationException exception) {
            throw new IOException("无法恢复测试仓库", exception);
        }
    }

    private boolean sawBuildIdentityMeRequest() {
        HttpUrl buildBase = HttpUrl.get(BuildConfig.DEFAULT_API_BASE_URL);
        String mePath = AppEdition.role().mePath();
        for (String value : buildIdentityRequests) {
            HttpUrl url = HttpUrl.get(value);
            if (sameOrigin(buildBase, url) && url.encodedPath().endsWith(mePath)) return true;
        }
        return false;
    }

    private static boolean sameOrigin(HttpUrl expected, HttpUrl actual) {
        return expected.scheme().equals(actual.scheme())
            && expected.host().equals(actual.host())
            && expected.port() == actual.port();
    }

    private MockResponse rejected(int status, String message) {
        return new MockResponse().setResponseCode(status)
            .setHeader("Content-Type", "application/json; charset=utf-8")
            .setBody("{\"code\":" + status + ",\"msg\":\"" + message + "\",\"data\":{}}");
    }

    private String meResponse() {
        if (AppEdition.role() == Role.PLATFORM) {
            return "{\"code\":1,\"msg\":\"操作成功\",\"data\":{\"platform\":{"
                + "\"id\":1,\"level\":" + AppEdition.requiredPlatformLevel() + ","
                + "\"platform_key\":\"" + BuildConfig.DEFAULT_PLATFORM_KEY + "\","
                + "\"account\":\"" + AppEdition.defaultAccount() + "\","
                + "\"nickname\":\"平台测试账号\",\"status\":1}}}";
        }
        if (AppEdition.role() == Role.ADMIN) {
            return "{\"code\":1,\"msg\":\"操作成功\",\"data\":{\"admin\":{"
                + "\"id\":1,\"platform_key\":\"" + BuildConfig.DEFAULT_PLATFORM_KEY + "\","
                + "\"account\":\"" + AppEdition.defaultAccount() + "\","
                + "\"nickname\":\"管理员测试账号\",\"status\":1},"
                + "\"app_identity_verified\":true}}";
        }
        return "{\"code\":1,\"msg\":\"操作成功\",\"data\":{\"user\":{"
            + "\"id\":1,\"account\":\"" + AppEdition.defaultAccount() + "\","
            + "\"nickname\":\"用户测试账号\",\"status\":1}}}";
    }

    private String lifecycleResponse() {
        return "{\"code\":1,\"msg\":\"操作成功\",\"data\":{"
            + "\"update\":{\"available\":false,\"force_update\":false},"
            + "\"maintenance\":{\"active\":false,\"forced\":false}}}";
    }

    private String initialResponse() {
        if (AppEdition.role() == Role.USER) {
            return "{\"code\":1,\"msg\":\"操作成功\",\"data\":{"
                + "\"app\":{\"name\":\"演示应用\",\"description\":\"运行测试\",\"version\":\"1.0\",\"logo\":\"\"},"
                + "\"banners\":[],\"notices\":[],\"features\":{}}}";
        }
        if (AppEdition.role() == Role.ADMIN) {
            return "{\"code\":1,\"msg\":\"操作成功\",\"data\":{"
                + "\"summary\":{\"users\":1,\"documents\":2},\"finance\":{},\"api\":{},\"daily\":[]}}";
        }
        return "{\"code\":1,\"msg\":\"操作成功\",\"data\":{"
            + "\"scope\":{\"actor_level\":" + AppEdition.requiredPlatformLevel() + "},"
            + "\"summary\":{\"operators\":1,\"admins\":1,\"apps\":1,\"users\":1},"
            + "\"finance\":{\"paid_orders\":0},\"daily\":[]}}";
    }
}
