package xyz.jjmxg.yiyunying.ui.auth;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;

import android.view.View;
import android.widget.TextView;

import androidx.test.core.app.ApplicationProvider;

import com.google.android.material.textfield.TextInputEditText;

import org.junit.Test;
import org.junit.runner.RunWith;
import org.robolectric.Robolectric;
import org.robolectric.RobolectricTestRunner;
import org.robolectric.android.controller.ActivityController;
import org.robolectric.annotation.Config;

import java.io.IOException;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;

import xyz.jjmxg.yiyunying.BuildConfig;
import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.YiyunyingApplication;
import xyz.jjmxg.yiyunying.data.session.SessionManager;

@RunWith(RobolectricTestRunner.class)
@Config(sdk = 34, application = YiyunyingApplication.class)
public final class LoginConnectionIdentityUiTest {
    @Test
    public void maliciousLegacySessionCannotOverrideInstalledBuildIdentity() {
        YiyunyingApplication application = ApplicationProvider.getApplicationContext();
        SessionManager session = application.container().session();
        session.clearAuthentication();
        session.configureConnection(
            "https://malicious-session.example.test/api/",
            "malicious-session-app-key",
            "malicious-session-platform-key"
        );

        try (ActivityController<LoginActivity> controller =
                 Robolectric.buildActivity(LoginActivity.class).setup()) {
            LoginActivity activity = controller.get();
            assertEquals(View.GONE, activity.findViewById(R.id.serverLayout).getVisibility());
            assertEquals(View.GONE, activity.findViewById(R.id.platformKeyLayout).getVisibility());
            assertEquals(View.GONE, activity.findViewById(R.id.appKeyLayout).getVisibility());

            assertEquals(
                BuildConfig.DEFAULT_API_BASE_URL,
                text(activity.findViewById(R.id.serverInput))
            );
            assertEquals(BuildConfig.DEFAULT_APP_KEY, text(activity.findViewById(R.id.appKeyInput)));
            assertEquals(
                BuildConfig.DEFAULT_PLATFORM_KEY,
                text(activity.findViewById(R.id.platformKeyInput))
            );
            assertFalse(text(activity.findViewById(R.id.serverInput)).contains("malicious-session"));
            assertFalse(text(activity.findViewById(R.id.appKeyInput)).contains("malicious-session"));
            assertFalse(text(activity.findViewById(R.id.platformKeyInput)).contains("malicious-session"));

            TextView notice = activity.findViewById(R.id.connectionSecurityNotice);
            assertEquals(View.VISIBLE, notice.getVisibility());
            assertTrue(notice.getText().toString().contains("当前安装版本"));
            assertTrue(notice.getText().toString().contains("实时登录状态"));
        }
    }

    @Test
    public void sourceKeepsAuthenticationPipelineAndUsesVisibleGenericConfigurationFeedback()
        throws IOException {
        String source = read("src/main/java/xyz/jjmxg/yiyunying/ui/auth/LoginActivity.java");
        assertTrue(source.contains("binding.serverInput.setText(BuildConfig.DEFAULT_API_BASE_URL);"));
        assertTrue(source.contains("binding.platformKeyInput.setText(BuildConfig.DEFAULT_PLATFORM_KEY);"));
        assertTrue(source.contains("binding.appKeyInput.setText(BuildConfig.DEFAULT_APP_KEY);"));
        assertFalse(source.contains("binding.serverInput.setText(session.baseUrl())"));
        assertFalse(source.contains("binding.platformKeyInput.setText(session.platformKey())"));
        assertFalse(source.contains("binding.appKeyInput.setText(session.appKey())"));
        assertTrue(source.contains("String server = BuildConfig.DEFAULT_API_BASE_URL;"));
        assertTrue(source.contains("String platformKey = BuildConfig.DEFAULT_PLATFORM_KEY;"));
        assertTrue(source.contains("String appKey = BuildConfig.DEFAULT_APP_KEY;"));
        assertFalse(source.contains("String server = text(binding.serverInput.getText())"));
        assertFalse(source.contains("String platformKey = text(binding.platformKeyInput.getText())"));
        assertFalse(source.contains("String appKey = text(binding.appKeyInput.getText())"));
        assertTrue(source.contains("session.configureConnection(server, appKey, platformKey);"));
        assertTrue(source.contains(
            "auth().login(selectedRole, server, platformKey, appKey, account, password"));
        assertTrue(source.contains("applyBuildIdentity(session)"));
        assertTrue(source.contains("selectedRole.mePath()"));
        assertTrue(source.contains("liveIdentityMatches(session, result.dataObject())"));
        assertTrue(source.contains("R.string.login_connection_config_error"));
        assertFalse(source.contains("binding.serverLayout.setError(exception.getMessage())"));
        assertFalse(source.contains("binding.platformKeyLayout.setError(\""));
        assertFalse(source.contains("binding.appKeyLayout.setError(\""));
    }

    @Test
    public void everyEditionSendsItsCompiledTenantIdentityAndPlatformBackendVerifiesIt()
        throws IOException {
        String repository = read("src/main/java/xyz/jjmxg/yiyunying/data/repository/AuthRepository.java");
        String session = read("src/main/java/xyz/jjmxg/yiyunying/data/session/SessionManager.java");
        assertTrue(repository.contains("role == Role.ADMIN || role == Role.PLATFORM"));
        assertTrue(repository.contains("role == Role.ADMIN || role == Role.USER"));
        assertTrue(repository.contains("body.addProperty(\"platform_key\", platformKey.trim())"));
        assertTrue(repository.contains("body.addProperty(\"app_key\", appKey.trim())"));
        assertTrue(session.contains("reconcileEditionIdentity();"));
        assertTrue(session.contains("endpointChanged || tenantChanged"));
        assertTrue(session.contains(".putString(KEY_BASE_URL, buildBase)"));
    }

    @Test
    public void layoutStartsHiddenAndLoginActionsUseChineseResources() throws IOException {
        String layout = read("src/main/res/layout/activity_login.xml");
        String strings = read("src/main/res/values/strings.xml");
        assertLayoutStartsHidden(layout, "serverLayout");
        assertLayoutStartsHidden(layout, "platformKeyLayout");
        assertLayoutStartsHidden(layout, "appKeyLayout");
        assertTrue(layout.contains("android:text=\"@string/card_login\""));
        assertTrue(layout.contains("android:text=\"@string/card_auto_login\""));
        assertTrue(layout.contains("android:text=\"@string/forgot_password\""));
        assertTrue(strings.contains("<string name=\"login_connection_security_notice\" translatable=\"false\">服务器与应用身份已由当前安装版本安全配置"));
        assertTrue(strings.contains("<string name=\"login_connection_config_error\" translatable=\"false\">安装包身份配置无效"));
        assertTrue(strings.contains("<string name=\"card_login\" translatable=\"false\">登录卡密</string>"));
        assertTrue(strings.contains("<string name=\"card_auto_login\" translatable=\"false\">使用本机已绑定卡密登录</string>"));
        assertTrue(strings.contains("<string name=\"forgot_password\" translatable=\"false\">找回密码</string>"));
    }

    private static String text(View view) {
        CharSequence value = ((TextInputEditText) view).getText();
        return value == null ? "" : value.toString();
    }

    private static void assertLayoutStartsHidden(String layout, String id) {
        int start = layout.indexOf("android:id=\"@+id/" + id + "\"");
        assertTrue(start >= 0);
        int tagEnd = layout.indexOf('>', start);
        assertTrue(tagEnd > start);
        assertTrue(layout.substring(start, tagEnd).contains("android:visibility=\"gone\""));
    }

    private static String read(String relative) throws IOException {
        Path path = Path.of(relative);
        if (!Files.exists(path)) path = Path.of("app").resolve(relative);
        return new String(Files.readAllBytes(path), StandardCharsets.UTF_8);
    }
}
