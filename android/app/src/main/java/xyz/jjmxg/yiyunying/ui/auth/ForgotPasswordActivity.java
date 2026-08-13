package xyz.jjmxg.yiyunying.ui.auth;

import android.content.Context;
import android.content.Intent;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.os.SystemClock;
import android.view.View;

import com.google.android.material.snackbar.Snackbar;
import com.google.gson.JsonObject;

import java.nio.charset.StandardCharsets;

import xyz.jjmxg.yiyunying.core.AppAccess;
import xyz.jjmxg.yiyunying.data.api.RequestHandle;
import xyz.jjmxg.yiyunying.databinding.ActivityForgotPasswordBinding;
import xyz.jjmxg.yiyunying.ui.common.SystemInsetActivity;

public final class ForgotPasswordActivity extends SystemInsetActivity {
    private ActivityForgotPasswordBinding binding;
    private RequestHandle request;
    private long resendAvailableAtMs;
    private final Handler handler = new Handler(Looper.getMainLooper());
    private final Runnable countdownTick = this::updateCountdown;

    public static void open(Context context) {
        context.startActivity(new Intent(context, ForgotPasswordActivity.class));
    }

    @Override protected void onCreate(Bundle state) {
        super.onCreate(state);
        binding = ActivityForgotPasswordBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());
        binding.toolbar.setNavigationOnClickListener(view -> finish());
        binding.accountInput.setText(AppAccess.from(this).session().account());
        binding.sendCodeButton.setOnClickListener(view -> sendCode());
        binding.resetButton.setOnClickListener(view -> reset());
    }

    private void sendCode() {
        clearErrors();
        String account = text(binding.accountInput.getText());
        String contact = text(binding.contactInput.getText());
        if (account.isEmpty()) { binding.accountLayout.setError("请输入账号"); return; }
        if (contact.isEmpty()) { binding.contactLayout.setError("请输入绑定的邮箱或手机号"); return; }
        JsonObject body = new JsonObject();
        body.addProperty("app_key", AppAccess.from(this).session().appKey());
        body.addProperty("account", account);
        body.addProperty("email_or_phone", contact);
        execute("/api/user/password/reset/code", body, true);
    }

    private void reset() {
        clearErrors();
        String account = text(binding.accountInput.getText());
        String contact = text(binding.contactInput.getText());
        String code = text(binding.codeInput.getText());
        String password = raw(binding.passwordInput.getText());
        String confirmation = raw(binding.confirmPasswordInput.getText());
        if (account.isEmpty()) { binding.accountLayout.setError("请输入账号"); return; }
        if (contact.isEmpty()) { binding.contactLayout.setError("请输入绑定的邮箱或手机号"); return; }
        if (code.isEmpty()) { binding.codeLayout.setError("请输入验证码"); return; }
        int passwordBytes = password.getBytes(StandardCharsets.UTF_8).length;
        if (passwordBytes < 8 || passwordBytes > 72) {
            binding.passwordLayout.setError("新密码长度需为 8-72 个字节");
            return;
        }
        if (!password.equals(confirmation)) { binding.confirmPasswordLayout.setError("两次输入的新密码不一致"); return; }
        JsonObject body = new JsonObject();
        body.addProperty("app_key", AppAccess.from(this).session().appKey());
        body.addProperty("account", account);
        body.addProperty("email_or_phone", contact);
        body.addProperty("code", code);
        body.addProperty("new_password", password);
        body.addProperty("new_password_confirmation", confirmation);
        execute("/api/user/password/reset", body, false);
    }

    private void execute(String path, JsonObject body, boolean codeRequest) {
        if (request != null) return;
        setLoading(true);
        request = AppAccess.from(this).repository().postPublic(path, body, result -> {
            request = null;
            if (binding == null) return;
            setLoading(false);
            if (codeRequest && result.isSuccessful()) {
                JsonObject delivery = result.dataObject();
                if (!EmailCodeDelivery.accepted(delivery)) {
                    Snackbar.make(binding.getRoot(), result.message().isEmpty()
                        ? "验证码投递状态未确认，未开始重发倒计时"
                        : result.message(), Snackbar.LENGTH_LONG).show();
                    return;
                }
                Snackbar.make(binding.getRoot(), result.message().isEmpty()
                    ? EmailCodeDelivery.fallbackMessage(delivery)
                    : result.message(), Snackbar.LENGTH_LONG).show();
                startCountdown(EmailCodeDelivery.retrySeconds(delivery));
                return;
            }
            Snackbar.make(binding.getRoot(), result.isSuccessful()
                ? (result.message().isEmpty() ? "密码重置成功" : result.message())
                : (result.message().isEmpty() ? (codeRequest ? "验证码投递失败" : "密码重置失败") : result.message()), Snackbar.LENGTH_LONG).show();
            if (result.isSuccessful() && !codeRequest) binding.getRoot().postDelayed(this::finish, 900L);
        });
    }

    private void startCountdown(int seconds) {
        handler.removeCallbacks(countdownTick);
        resendAvailableAtMs = SystemClock.elapsedRealtime() + seconds * 1000L;
        updateCountdown();
    }

    private void updateCountdown() {
        if (binding == null) return;
        long remainingMs = resendAvailableAtMs - SystemClock.elapsedRealtime();
        if (remainingMs <= 0) {
            resendAvailableAtMs = 0L;
            binding.sendCodeButton.setText("重新获取验证码");
            binding.sendCodeButton.setEnabled(request == null);
            return;
        }
        int remaining = (int) Math.ceil(remainingMs / 1000d);
        binding.sendCodeButton.setText(remaining + " 秒后可重新获取");
        binding.sendCodeButton.setEnabled(false);
        handler.postDelayed(countdownTick, Math.min(1000L, remainingMs));
    }

    private void setLoading(boolean loading) {
        binding.progress.setVisibility(loading ? View.VISIBLE : View.INVISIBLE);
        binding.sendCodeButton.setEnabled(!loading && resendAvailableAtMs <= SystemClock.elapsedRealtime());
        binding.resetButton.setEnabled(!loading);
    }

    private void clearErrors() {
        binding.accountLayout.setError(null);
        binding.contactLayout.setError(null);
        binding.codeLayout.setError(null);
        binding.passwordLayout.setError(null);
        binding.confirmPasswordLayout.setError(null);
    }

    private static String text(CharSequence value) { return value == null ? "" : value.toString().trim(); }
    private static String raw(CharSequence value) { return value == null ? "" : value.toString(); }

    @Override protected void onDestroy() {
        if (request != null) request.cancel();
        handler.removeCallbacks(countdownTick);
        binding = null;
        super.onDestroy();
    }
}
