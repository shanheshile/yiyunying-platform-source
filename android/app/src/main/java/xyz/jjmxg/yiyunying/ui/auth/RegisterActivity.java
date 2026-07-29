package xyz.jjmxg.yiyunying.ui.auth;

import android.content.Intent;
import android.content.ClipData;
import android.content.ClipboardManager;
import android.content.Context;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.view.View;

import androidx.appcompat.app.AppCompatActivity;

import com.google.android.material.snackbar.Snackbar;
import com.google.gson.JsonObject;

import java.util.LinkedHashMap;
import java.util.Map;

import xyz.jjmxg.yiyunying.BuildConfig;
import xyz.jjmxg.yiyunying.core.AppAccess;
import xyz.jjmxg.yiyunying.data.api.RequestHandle;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.databinding.ActivityRegisterBinding;
import xyz.jjmxg.yiyunying.domain.Role;
import xyz.jjmxg.yiyunying.data.session.SessionManager;
import xyz.jjmxg.yiyunying.ui.main.MainActivity;

public final class RegisterActivity extends xyz.jjmxg.yiyunying.ui.common.SystemInsetActivity {
    public static final String EXTRA_ROLE = "role";
    public static final String EXTRA_BASE_URL = "base_url";
    public static final String EXTRA_PLATFORM_KEY = "platform_key";
    public static final String EXTRA_APP_KEY = "app_key";

    private ActivityRegisterBinding binding;
    private Role role;
    private RequestHandle request;
    private RequestHandle policyRequest;
    private RequestHandle emailCodeRequest;
    private boolean nicknameRequired;
    private boolean emailRequired;
    private boolean phoneRequired;
    private boolean emailVerificationRequired;
    private final Handler handler = new Handler(Looper.getMainLooper());

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        role = Role.fromWireName(getIntent().getStringExtra(EXTRA_ROLE));
        if (role == Role.PLATFORM) {
            finish();
            return;
        }
        binding = ActivityRegisterBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());
        binding.toolbar.setNavigationOnClickListener(view -> finish());
        binding.roleHint.setText(role == Role.ADMIN ? "注册管理员账号" : "注册应用用户");
        binding.inviteCodeLayout.setVisibility(role == Role.USER ? View.VISIBLE : View.GONE);
        binding.inviteCodeLayout.setEndIconOnClickListener(view -> pasteInviteCode(true));
        if (role == Role.USER) binding.inviteCodeLayout.post(() -> pasteInviteCode(false));
        binding.registerButton.setOnClickListener(view -> submit());
        binding.sendEmailCodeButton.setOnClickListener(view -> sendEmailCode());
        SessionManager session = AppAccess.from(this).session();
        session.configureConnection(baseUrl(), appKey(), platformKey());
        if (role == Role.USER) {
            binding.emailLayout.setVisibility(View.GONE);
            binding.phoneLayout.setVisibility(View.GONE);
            loadRegistrationPolicy();
        } else {
            nicknameRequired = false;
            emailRequired = false;
            phoneRequired = false;
            emailVerificationRequired = false;
        }
        binding.formContainer.post(this::constrainWidth);
    }

    private void submit() {
        binding.accountLayout.setError(null);
        binding.passwordLayout.setError(null);
        binding.passwordConfirmationLayout.setError(null);
        binding.nicknameLayout.setError(null);
        binding.emailLayout.setError(null);
        binding.emailCodeLayout.setError(null);
        binding.phoneLayout.setError(null);
        String account = text(binding.accountInput.getText());
        String password = binding.passwordInput.getText() == null ? "" : binding.passwordInput.getText().toString();
        String confirmation = binding.passwordConfirmationInput.getText() == null ? "" : binding.passwordConfirmationInput.getText().toString();
        String nickname = text(binding.nicknameInput.getText());
        String email = text(binding.emailInput.getText());
        String phone = text(binding.phoneInput.getText());
        String emailCode = text(binding.emailCodeInput.getText());
        if (account.length() < 3) {
            binding.accountLayout.setError("账号至少 3 个字符");
            return;
        }
        if (password.length() < 6) {
            binding.passwordLayout.setError("密码至少 6 个字符");
            return;
        }
        if (!password.equals(confirmation)) {
            binding.passwordConfirmationLayout.setError("两次输入的密码不一致");
            return;
        }
        if (nicknameRequired && nickname.isEmpty()) {
            binding.nicknameLayout.setError("请输入昵称");
            return;
        }
        if (emailRequired && email.isEmpty()) {
            binding.emailLayout.setError("请输入邮箱");
            return;
        }
        if (phoneRequired && phone.isEmpty()) {
            binding.phoneLayout.setError("请输入手机号");
            return;
        }
        if (emailVerificationRequired && !email.isEmpty() && emailCode.length() != 6) {
            binding.emailCodeLayout.setError("请输入 6 位邮箱验证码");
            return;
        }
        JsonObject fields = new JsonObject();
        fields.addProperty("account", account);
        fields.addProperty("password", password);
        fields.addProperty("password_confirmation", confirmation);
        addIfPresent(fields, "nickname", nickname);
        addIfPresent(fields, "email", email);
        addIfPresent(fields, "email_code", emailCode);
        addIfPresent(fields, "phone", phone);
        if (role == Role.USER) addIfPresent(fields, "invite_code", binding.inviteCodeInput.getText());
        setLoading(true);
        request = AppAccess.from(this).auth().register(
            role,
            baseUrl(),
            platformKey(),
            appKey(),
            fields,
            result -> {
                setLoading(false);
                if (!result.isSuccessful()) {
                    Snackbar.make(binding.getRoot(), result.message().isEmpty() ? "注册失败" : result.message(), Snackbar.LENGTH_LONG).show();
                    return;
                }
                if (role == Role.USER) {
                    if (Jsons.string(result.dataObject(), "access_token").isEmpty()) {
                        Snackbar.make(binding.getRoot(), result.message().isEmpty() ? "注册成功，请开通会员后登录" : result.message(), Snackbar.LENGTH_LONG).show();
                        binding.getRoot().postDelayed(this::finish, 1600L);
                        return;
                    }
                    startActivity(new Intent(this, MainActivity.class).addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP));
                    finishAffinity();
                } else {
                    setResult(RESULT_OK);
                    finish();
                }
            }
        );
    }

    private void loadRegistrationPolicy() {
        binding.progress.setVisibility(View.VISIBLE);
        binding.registerButton.setEnabled(false);
        Map<String, String> query = new LinkedHashMap<>();
        query.put("app_key", appKey());
        policyRequest = AppAccess.from(this).repository().get("/api/public/bootstrap", query, result -> {
            policyRequest = null;
            if (binding == null) return;
            binding.progress.setVisibility(View.INVISIBLE);
            if (!result.isSuccessful()) {
                Snackbar.make(binding.getRoot(), result.message().isEmpty() ? "无法读取注册规则" : result.message(), Snackbar.LENGTH_LONG).show();
                return;
            }
            JsonObject policy = Jsons.object(result.dataObject(), "registration_policy");
            boolean registrationEnabled = booleanValue(Jsons.object(result.dataObject(), "settings"), "registration_enabled", true);
            applyPolicyField(binding.nicknameLayout, Jsons.object(policy, "nickname"));
            applyPolicyField(binding.emailLayout, Jsons.object(policy, "email"));
            applyPolicyField(binding.phoneLayout, Jsons.object(policy, "phone"));
            JsonObject nickname = Jsons.object(policy, "nickname");
            JsonObject email = Jsons.object(policy, "email");
            JsonObject phone = Jsons.object(policy, "phone");
            nicknameRequired = booleanValue(nickname, "required", false);
            emailRequired = booleanValue(email, "required", false);
            phoneRequired = booleanValue(phone, "required", false);
            emailVerificationRequired = booleanValue(email, "verification_required", false);
            boolean showEmailVerification = binding.emailLayout.getVisibility() == View.VISIBLE && emailVerificationRequired;
            binding.sendEmailCodeButton.setVisibility(showEmailVerification ? View.VISIBLE : View.GONE);
            binding.emailCodeLayout.setVisibility(showEmailVerification ? View.VISIBLE : View.GONE);
            binding.registerButton.setEnabled(registrationEnabled);
            if (!registrationEnabled) {
                binding.roleHint.setText("当前应用已暂停新用户注册");
                Snackbar.make(binding.getRoot(), "当前应用已关闭用户注册", Snackbar.LENGTH_LONG).show();
            }
        });
    }

    private void applyPolicyField(View field, JsonObject policy) {
        field.setVisibility(booleanValue(policy, "enabled", false) ? View.VISIBLE : View.GONE);
    }

    private void sendEmailCode() {
        String email = text(binding.emailInput.getText());
        binding.emailLayout.setError(null);
        if (email.isEmpty() || !android.util.Patterns.EMAIL_ADDRESS.matcher(email).matches()) {
            binding.emailLayout.setError("请输入正确的邮箱");
            return;
        }
        JsonObject body = new JsonObject();
        body.addProperty("app_key", appKey());
        body.addProperty("email", email);
        body.addProperty("scene", "register");
        binding.sendEmailCodeButton.setEnabled(false);
        emailCodeRequest = AppAccess.from(this).repository().post("/api/public/verification-code/email", body, result -> {
            emailCodeRequest = null;
            if (binding == null) return;
            if (!result.isSuccessful()) {
                binding.sendEmailCodeButton.setEnabled(true);
                Snackbar.make(binding.getRoot(), result.message().isEmpty() ? "验证码发送失败" : result.message(), Snackbar.LENGTH_LONG).show();
                return;
            }
            Snackbar.make(binding.getRoot(), result.message().isEmpty() ? "验证码已发送" : result.message(), Snackbar.LENGTH_LONG).show();
            startEmailCountdown(60);
        });
    }

    private void startEmailCountdown(int remaining) {
        if (binding == null) return;
        if (remaining <= 0) {
            binding.sendEmailCodeButton.setText("重新发送邮箱验证码");
            binding.sendEmailCodeButton.setEnabled(true);
            return;
        }
        binding.sendEmailCodeButton.setText(remaining + " 秒后可重新发送");
        handler.postDelayed(() -> startEmailCountdown(remaining - 1), 1000L);
    }

    private void setLoading(boolean loading) {
        binding.progress.setVisibility(loading ? View.VISIBLE : View.INVISIBLE);
        binding.registerButton.setEnabled(!loading);
    }

    private void constrainWidth() {
        int available = getResources().getDisplayMetrics().widthPixels - dp(24);
        binding.formContainer.getLayoutParams().width = Math.min(available, dp(560));
        binding.formContainer.requestLayout();
    }

    private int dp(int value) { return Math.round(value * getResources().getDisplayMetrics().density); }
    private String baseUrl() {
        String value = getIntent().getStringExtra(EXTRA_BASE_URL);
        return value == null || value.trim().isEmpty() ? BuildConfig.DEFAULT_API_BASE_URL : value.trim();
    }
    private String appKey() {
        String value = getIntent().getStringExtra(EXTRA_APP_KEY);
        return value == null || value.trim().isEmpty() ? BuildConfig.DEFAULT_APP_KEY : value.trim();
    }
    private String platformKey() {
        String value = getIntent().getStringExtra(EXTRA_PLATFORM_KEY);
        return value == null || value.trim().isEmpty() ? BuildConfig.DEFAULT_PLATFORM_KEY : value.trim();
    }
    private static boolean booleanValue(JsonObject object, String key, boolean fallback) {
        try { return object.has(key) ? object.get(key).getAsBoolean() : fallback; }
        catch (RuntimeException ignored) { return fallback; }
    }
    private void pasteInviteCode(boolean showResult) {
        ClipboardManager manager = (ClipboardManager) getSystemService(Context.CLIPBOARD_SERVICE);
        if (manager == null || !manager.hasPrimaryClip()) {
            if (showResult) Snackbar.make(binding.getRoot(), "剪贴板中没有邀请码", Snackbar.LENGTH_SHORT).show();
            return;
        }
        ClipData clip = manager.getPrimaryClip();
        CharSequence value = clip == null || clip.getItemCount() == 0 ? null : clip.getItemAt(0).coerceToText(this);
        String code = text(value).toUpperCase(java.util.Locale.ROOT);
        if (!code.matches("[A-Z0-9_-]{6,32}")) {
            if (showResult) Snackbar.make(binding.getRoot(), "剪贴板内容不像有效邀请码", Snackbar.LENGTH_SHORT).show();
            return;
        }
        binding.inviteCodeInput.setText(code);
        binding.inviteCodeInput.setSelection(code.length());
        Snackbar.make(binding.getRoot(), "已识别剪贴板邀请码", Snackbar.LENGTH_SHORT).show();
    }
    private static String text(CharSequence value) { return value == null ? "" : value.toString().trim(); }
    private static void addIfPresent(JsonObject object, String key, CharSequence value) {
        String text = text(value);
        if (!text.isEmpty()) object.addProperty(key, text);
    }

    @Override protected void onDestroy() {
        if (request != null) request.cancel();
        if (policyRequest != null) policyRequest.cancel();
        if (emailCodeRequest != null) emailCodeRequest.cancel();
        handler.removeCallbacksAndMessages(null);
        super.onDestroy();
    }
}
