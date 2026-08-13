package xyz.jjmxg.yiyunying.ui.auth;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;

import android.content.Context;
import android.view.Gravity;
import android.view.ContextThemeWrapper;
import android.view.LayoutInflater;
import android.view.View;
import android.widget.FrameLayout;

import androidx.test.core.app.ApplicationProvider;

import com.google.android.material.textfield.TextInputEditText;
import com.google.gson.JsonObject;

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

@RunWith(RobolectricTestRunner.class)
@Config(sdk = 34, application = YiyunyingApplication.class)
public final class AuthenticationEntryRegressionTest {
    @Test
    public void freshUserEditionDoesNotDisplayBuildRoleMarkerAsAccount() {
        if (!"user".equals(BuildConfig.FIXED_ROLE)) return;
        Context context = ApplicationProvider.getApplicationContext();
        context.getSharedPreferences("yiyunying.session.v1", Context.MODE_PRIVATE)
            .edit()
            .clear()
            .commit();

        try (ActivityController<LoginActivity> controller =
                 Robolectric.buildActivity(LoginActivity.class).setup()) {
            TextInputEditText account = controller.get().findViewById(R.id.accountInput);
            assertEquals("", account.getText() == null ? "" : account.getText().toString());
        }
    }

    @Test
    public void registrationFormIsCenteredInsideItsScrollableViewport() {
        Context context = ApplicationProvider.getApplicationContext();
        Context themed = new ContextThemeWrapper(context, R.style.Theme_Yiyunying);
        View root = LayoutInflater.from(themed).inflate(R.layout.activity_register, null, false);
        View form = root.findViewById(R.id.formContainer);
        assertTrue(form.getLayoutParams() instanceof FrameLayout.LayoutParams);
        FrameLayout.LayoutParams params = (FrameLayout.LayoutParams) form.getLayoutParams();
        assertEquals(Gravity.CENTER_HORIZONTAL, params.gravity & Gravity.HORIZONTAL_GRAVITY_MASK);
    }

    @Test
    public void countdownStartsOnlyForAnExplicitTransportReceipt() {
        JsonObject accepted = new JsonObject();
        accepted.addProperty("delivery_status", "accepted_unconfirmed");
        accepted.addProperty("retry_after_seconds", 999);
        assertTrue(EmailCodeDelivery.accepted(accepted));
        assertEquals(300, EmailCodeDelivery.retrySeconds(accepted));
        assertTrue(EmailCodeDelivery.fallbackMessage(accepted).contains("最终送达尚未确认"));
        assertFalse(EmailCodeDelivery.fallbackMessage(accepted).contains("已发送"));

        JsonObject simulated = new JsonObject();
        simulated.addProperty("delivery_status", "simulated");
        assertFalse(EmailCodeDelivery.accepted(simulated));
        assertFalse(EmailCodeDelivery.accepted(new JsonObject()));
    }

    @Test
    public void androidUsesPublicEndpointAndBackendAlignedParameters() throws IOException {
        String register = read("src/main/java/xyz/jjmxg/yiyunying/ui/auth/RegisterActivity.java");
        String login = read("src/main/java/xyz/jjmxg/yiyunying/data/repository/AuthRepository.java");
        assertTrue(register.contains("postPublic(\"/api/public/verification-code/email\""));
        assertTrue(register.contains("body.addProperty(\"app_key\", appKey())"));
        assertTrue(register.contains("body.addProperty(\"email\", email)"));
        assertTrue(register.contains("body.addProperty(\"scene\", \"register\")"));
        assertTrue(register.contains("EmailCodeDelivery.accepted(delivery)"));
        assertTrue(register.contains("SystemClock.elapsedRealtime()"));
        assertFalse(register.contains("验证码已发送"));

        String forgot = read("src/main/java/xyz/jjmxg/yiyunying/ui/auth/ForgotPasswordActivity.java");
        assertTrue(forgot.contains("EmailCodeDelivery.accepted(delivery)"));
        assertTrue(forgot.contains("startCountdown(EmailCodeDelivery.retrySeconds(delivery))"));
        assertTrue(forgot.contains("SystemClock.elapsedRealtime()"));
        assertFalse(forgot.contains("验证码已发送"));

        assertTrue(login.contains("body.addProperty(\"account\", account.trim())"));
        assertTrue(login.contains("body.addProperty(\"password\", password)"));
        assertTrue(login.contains("body.addProperty(\"device\", deviceName())"));
        assertTrue(login.contains("body.addProperty(\"platform_key\", platformKey.trim())"));
        assertTrue(login.contains("body.addProperty(\"app_key\", appKey.trim())"));
    }

    private static String read(String relative) throws IOException {
        Path path = Path.of(relative);
        if (!Files.exists(path)) path = Path.of("app").resolve(relative);
        return new String(Files.readAllBytes(path), StandardCharsets.UTF_8);
    }
}
