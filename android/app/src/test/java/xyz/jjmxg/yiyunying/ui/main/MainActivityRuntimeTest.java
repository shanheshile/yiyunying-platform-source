package xyz.jjmxg.yiyunying.ui.main;

import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertNotNull;
import static org.junit.Assert.assertTrue;
import static org.robolectric.Shadows.shadowOf;

import android.os.Looper;
import android.os.Bundle;
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

import okhttp3.mockwebserver.MockResponse;
import okhttp3.mockwebserver.MockWebServer;
import okhttp3.mockwebserver.Dispatcher;
import okhttp3.mockwebserver.RecordedRequest;
import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.YiyunyingApplication;
import xyz.jjmxg.yiyunying.core.AppAccess;
import xyz.jjmxg.yiyunying.data.session.SessionManager;
import xyz.jjmxg.yiyunying.domain.AppEdition;
import xyz.jjmxg.yiyunying.domain.Role;

@RunWith(RobolectricTestRunner.class)
@Config(sdk = 34, application = YiyunyingApplication.class)
public final class MainActivityRuntimeTest {
    private MockWebServer server;
    private SessionManager session;

    @Before
    public void setUp() throws IOException {
        server = new MockWebServer();
        server.setDispatcher(new Dispatcher() {
            @Override
            public MockResponse dispatch(RecordedRequest request) {
                String path = request.getPath() == null ? "" : request.getPath();
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
        session = AppAccess.from(ApplicationProvider.getApplicationContext()).session();
        session.clearAuthentication();
        session.configureConnection(server.url("/").toString(), "yiyunying-demo", platformKey());
        Role role = AppEdition.role();
        int actorLevel = role == Role.PLATFORM ? AppEdition.requiredPlatformLevel() : role == Role.ADMIN ? 3 : 4;
        session.saveAuthenticated(role, AppEdition.defaultAccount(), "runtime-token", "refresh-token",
            "2099-01-01 00:00:00", "2099-02-01 00:00:00", "yiyunying-demo", 1L, actorLevel);
        if (role == Role.ADMIN) session.selectApp(1L, "演示应用", "yiyunying-demo");
    }

    @After
    public void tearDown() throws IOException {
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

    private String platformKey() {
        return AppEdition.requiredPlatformLevel() == 2 ? "yiyunying-authorized" : "yiyunying-root";
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
