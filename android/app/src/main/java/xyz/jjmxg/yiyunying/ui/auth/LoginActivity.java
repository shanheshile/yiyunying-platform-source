package xyz.jjmxg.yiyunying.ui.auth;

import android.content.Intent;
import android.os.Bundle;
import android.view.View;
import android.view.inputmethod.EditorInfo;
import android.widget.FrameLayout;

import androidx.appcompat.app.AlertDialog;

import com.google.gson.JsonObject;

import java.util.Collections;

import xyz.jjmxg.yiyunying.ui.common.YiyunyingDialogBuilder;
import xyz.jjmxg.yiyunying.ui.common.SafeTextInput;
import com.google.android.material.snackbar.Snackbar;
import com.google.android.material.textfield.TextInputEditText;
import com.google.android.material.textfield.TextInputLayout;

import xyz.jjmxg.yiyunying.BuildConfig;
import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.core.AppAccess;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.data.api.RequestHandle;
import xyz.jjmxg.yiyunying.data.session.EndpointPolicy;
import xyz.jjmxg.yiyunying.data.session.SessionManager;
import xyz.jjmxg.yiyunying.databinding.ActivityLoginBinding;
import xyz.jjmxg.yiyunying.domain.AppEdition;
import xyz.jjmxg.yiyunying.domain.Role;
import xyz.jjmxg.yiyunying.ui.main.MainActivity;
import xyz.jjmxg.yiyunying.ui.common.LifecycleChecker;
import xyz.jjmxg.yiyunying.ui.common.CrashNotice;

public final class LoginActivity extends xyz.jjmxg.yiyunying.ui.common.SystemInsetActivity {
    public static final String EXTRA_FORCE_LOGIN = "force_login";
    private ActivityLoginBinding binding;
    private Role selectedRole = AppEdition.role();
    private RequestHandle request;
    private boolean buildIdentityValid;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        SessionManager session = AppAccess.from(this).session();
        buildIdentityValid = applyBuildIdentity(session);
        boolean forceLogin = getIntent().getBooleanExtra(EXTRA_FORCE_LOGIN, false);
        if (forceLogin) {
            session.clearAuthentication();
        }
        if (session.isAuthenticated() && !session.isCompatibleWithEdition()) {
            session.clearAuthentication();
        }
        boolean validateExistingSession = buildIdentityValid
            && session.isCompatibleWithEdition()
            && !forceLogin;
        binding = ActivityLoginBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());
        CrashNotice.showPending(this);
        // Login identity must come from the installed edition. Values persisted by an older
        // version are deliberately ignored so a previously editable endpoint or tenant key can
        // never override the developer-provisioned build identity.
        binding.serverInput.setText(BuildConfig.DEFAULT_API_BASE_URL);
        binding.platformKeyInput.setText(BuildConfig.DEFAULT_PLATFORM_KEY);
        binding.appKeyInput.setText(BuildConfig.DEFAULT_APP_KEY);
        binding.accountInput.setText(session.account().isEmpty() ? AppEdition.defaultAccount() : session.account());
        binding.roleToggle.setVisibility(View.GONE);
        updateRole(selectedRole);
        binding.loginButton.setOnClickListener(view -> login());
        binding.cardLoginButton.setOnClickListener(view -> showCardLoginDialog());
        binding.cardAutoLoginButton.setOnClickListener(view -> cardLogin("", true));
        binding.registerButton.setOnClickListener(view -> register());
        binding.forgotPasswordButton.setOnClickListener(view -> ForgotPasswordActivity.open(this));
        binding.passwordInput.setOnEditorActionListener((view, actionId, event) -> {
            if (actionId == EditorInfo.IME_ACTION_DONE) {
                login();
                return true;
            }
            return false;
        });
        binding.formContainer.post(this::constrainFormWidth);
        if (!buildIdentityValid) {
            setAuthenticationEntryEnabled(false);
            showConnectionConfigurationError();
        } else if (validateExistingSession) {
            validateExistingSession(session);
        }
    }

    private boolean applyBuildIdentity(SessionManager session) {
        try {
            String normalized = EndpointPolicy.normalize(BuildConfig.DEFAULT_API_BASE_URL);
            String tenantKey = AppEdition.role() == Role.USER
                ? BuildConfig.DEFAULT_APP_KEY.trim()
                : BuildConfig.DEFAULT_PLATFORM_KEY.trim();
            if (tenantKey.isEmpty()
                || (AppEdition.role() == Role.ADMIN && BuildConfig.DEFAULT_APP_KEY.trim().isEmpty())) {
                throw new IllegalArgumentException("missing build tenant key");
            }
            if (AppEdition.role() == Role.PLATFORM
                && !java.util.Arrays.asList(1, 2).contains(AppEdition.requiredPlatformLevel())) {
                throw new IllegalArgumentException("invalid platform edition level");
            }
            boolean sameEndpoint = normalized.equals(session.baseUrl());
            boolean sameTenant = AppEdition.role() == Role.USER
                ? tenantKey.equals(session.appKey())
                : tenantKey.equals(session.platformKey());
            if (session.isAuthenticated() && (!sameEndpoint || !sameTenant)) {
                session.clearAuthentication();
            }
            session.configureConnection(
                BuildConfig.DEFAULT_API_BASE_URL,
                BuildConfig.DEFAULT_APP_KEY,
                BuildConfig.DEFAULT_PLATFORM_KEY
            );
            return true;
        } catch (IllegalArgumentException exception) {
            session.clearAuthentication();
            return false;
        }
    }

    private void validateExistingSession(SessionManager session) {
        setLoading(true);
        request = AppAccess.from(this).repository().get(
            selectedRole.mePath(),
            selectedRole == Role.ADMIN
                ? Collections.singletonMap("app_key", BuildConfig.DEFAULT_APP_KEY.trim())
                : Collections.emptyMap(),
            result -> {
                if (binding == null) return;
                setLoading(false);
                if (result.isSuccessful() && liveIdentityMatches(session, result.dataObject())) {
                    openMain();
                    return;
                }
                boolean rejected = result.isAuthenticationFailure() || result.httpCode() == 403 || result.isSuccessful();
                if (rejected) session.clearAuthentication();
                String message = rejected
                    ? "登录状态已失效或与当前安装版本不匹配，请重新登录"
                    : "暂时无法实时验证登录状态，请检查网络后重试";
                Snackbar.make(binding.getRoot(), message, Snackbar.LENGTH_LONG).show();
            }
        );
    }

    private boolean liveIdentityMatches(SessionManager session, JsonObject data) {
        JsonObject actor = Jsons.object(data, selectedRole.wireName());
        if (Jsons.longValue(actor, "id") != session.actorId()) return false;
        if (selectedRole == Role.USER) {
            // AuthService also matches the live token against X-App-Key for every user request.
            return BuildConfig.DEFAULT_APP_KEY.trim().equals(session.appKey());
        }
        if (selectedRole == Role.ADMIN && !booleanValue(data, "app_identity_verified")) return false;
        if (selectedRole == Role.PLATFORM) {
            int liveLevel = Jsons.intValue(actor, "level", 0);
            return liveLevel == AppEdition.requiredPlatformLevel()
                && liveLevel == session.actorLevel()
                && BuildConfig.DEFAULT_PLATFORM_KEY.trim().equals(Jsons.string(actor, "platform_key"));
        }
        return BuildConfig.DEFAULT_PLATFORM_KEY.trim().equals(Jsons.string(actor, "platform_key"));
    }

    private void updateRole(Role role) {
        selectedRole = role;
        // Connection and tenant identity are provisioned by the installed edition. Keep the
        // populated inputs in the view binding for the existing authentication pipeline, but do
        // not expose or allow editing them on any login screen.
        binding.serverLayout.setVisibility(View.GONE);
        binding.platformKeyLayout.setVisibility(View.GONE);
        binding.appKeyLayout.setVisibility(View.GONE);
        binding.registerButton.setVisibility(AppEdition.allowsSelfRegistration() ? View.VISIBLE : View.GONE);
        boolean userEdition = role == Role.USER;
        binding.forgotPasswordButton.setVisibility(userEdition ? View.VISIBLE : View.GONE);
        binding.cardLoginButton.setVisibility(userEdition ? View.VISIBLE : View.GONE);
        binding.cardAutoLoginButton.setVisibility(
            userEdition && AppAccess.from(this).session().hasCardBinding(BuildConfig.DEFAULT_APP_KEY)
                ? View.VISIBLE : View.GONE
        );
        String account = text(binding.accountInput.getText());
        if (account.isEmpty() || "root".equals(account) || "admin".equals(account) || "user".equals(account)) {
            binding.accountInput.setText(AppEdition.defaultAccount());
            binding.accountInput.setSelection(binding.accountInput.length());
        }
    }

    private void login() {
        if (!buildIdentityValid) {
            showConnectionConfigurationError();
            return;
        }
        clearErrors();
        String server = BuildConfig.DEFAULT_API_BASE_URL;
        String platformKey = BuildConfig.DEFAULT_PLATFORM_KEY;
        String appKey = BuildConfig.DEFAULT_APP_KEY;
        String account = text(binding.accountInput.getText());
        String password = binding.passwordInput.getText() == null ? "" : binding.passwordInput.getText().toString();
        try {
            EndpointPolicy.normalize(server);
        } catch (IllegalArgumentException exception) {
            showConnectionConfigurationError();
            return;
        }
        if (selectedRole != Role.USER && platformKey.isEmpty()) {
            showConnectionConfigurationError();
            return;
        }
        if (selectedRole == Role.USER && appKey.isEmpty()) {
            showConnectionConfigurationError();
            return;
        }
        if (account.isEmpty()) {
            binding.accountLayout.setError("账号不能为空");
            return;
        }
        if (password.isEmpty()) {
            binding.passwordLayout.setError("密码不能为空");
            return;
        }
        SessionManager session = AppAccess.from(this).session();
        session.configureConnection(server, appKey, platformKey);
        setLoading(true);
        request = LifecycleChecker.check(this, binding.getRoot(), () -> performLogin(server, platformKey, appKey, account, password));
    }

    private void performLogin(String server, String platformKey, String appKey, String account, String password) {
        request = AppAccess.from(this).auth().login(selectedRole, server, platformKey, appKey, account, password, result -> {
            setLoading(false);
            if (result.isSuccessful()) {
                binding.passwordInput.setText("");
                openMain();
            } else {
                String message = result.message().isEmpty() ? "登录失败" : result.message();
                Snackbar.make(binding.getRoot(), message, Snackbar.LENGTH_LONG).show();
            }
        });
    }

    private void showCardLoginDialog() {
        if (!buildIdentityValid) {
            showConnectionConfigurationError();
            return;
        }
        TextInputLayout layout = new TextInputLayout(this);
        layout.setHint("登录卡密");
        layout.setBoxBackgroundMode(TextInputLayout.BOX_BACKGROUND_OUTLINE);
        TextInputEditText input = new TextInputEditText(layout.getContext());
        input.setSingleLine(true);
        input.setImeOptions(EditorInfo.IME_ACTION_DONE);
        SafeTextInput.attach(layout, input);
        FrameLayout container = new FrameLayout(this);
        int horizontal = dp(24);
        int vertical = dp(8);
        container.setPadding(horizontal, vertical, horizontal, 0);
        container.addView(layout, new FrameLayout.LayoutParams(
            FrameLayout.LayoutParams.MATCH_PARENT,
            FrameLayout.LayoutParams.WRAP_CONTENT
        ));
        AlertDialog dialog = new YiyunyingDialogBuilder(this)
            .setTitle("登录卡密")
            .setMessage("首次使用会将卡密安全绑定到当前设备，之后可直接使用本机自动登录。")
            .setView(container)
            .setNegativeButton("取消", null)
            .setPositiveButton("登录", null)
            .create();
        dialog.setOnShowListener(ignored -> dialog.getButton(AlertDialog.BUTTON_POSITIVE).setOnClickListener(view -> {
            String code = text(input.getText());
            if (code.isEmpty()) {
                layout.setError("请输入登录卡密");
                return;
            }
            dialog.dismiss();
            cardLogin(code, false);
        }));
        input.setOnEditorActionListener((view, actionId, event) -> {
            if (actionId == EditorInfo.IME_ACTION_DONE) {
                dialog.getButton(AlertDialog.BUTTON_POSITIVE).performClick();
                return true;
            }
            return false;
        });
        dialog.show();
        input.requestFocus();
    }

    private void cardLogin(String cardCode, boolean automatic) {
        if (!buildIdentityValid) {
            showConnectionConfigurationError();
            return;
        }
        String server = BuildConfig.DEFAULT_API_BASE_URL;
        String appKey = BuildConfig.DEFAULT_APP_KEY;
        try {
            EndpointPolicy.normalize(server);
        } catch (IllegalArgumentException exception) {
            showConnectionConfigurationError();
            return;
        }
        if (appKey.isEmpty()) {
            showConnectionConfigurationError();
            return;
        }
        SessionManager session = AppAccess.from(this).session();
        if (automatic && !session.hasCardBinding(appKey)) {
            Snackbar.make(binding.getRoot(), "当前设备没有可用的登录卡绑定", Snackbar.LENGTH_LONG).show();
            return;
        }
        setLoading(true);
        request = LifecycleChecker.check(this, binding.getRoot(), () -> {
            if (automatic) {
                request = AppAccess.from(this).auth().autoLoginWithCard(server, appKey, this::handleCardLoginResult);
            } else {
                request = AppAccess.from(this).auth().loginWithCard(server, appKey, cardCode, this::handleCardLoginResult);
            }
        });
    }

    private void handleCardLoginResult(xyz.jjmxg.yiyunying.data.api.ApiResult result) {
        setLoading(false);
        if (result.isSuccessful()) {
            openMain();
            return;
        }
        String message = result.message().isEmpty() ? "登录卡密登录失败" : result.message();
        Snackbar.make(binding.getRoot(), message, Snackbar.LENGTH_LONG).show();
    }

    private void register() {
        if (!buildIdentityValid) {
            showConnectionConfigurationError();
            return;
        }
        Intent intent = new Intent(this, RegisterActivity.class);
        intent.putExtra(RegisterActivity.EXTRA_ROLE, selectedRole.wireName());
        intent.putExtra(RegisterActivity.EXTRA_BASE_URL, BuildConfig.DEFAULT_API_BASE_URL);
        intent.putExtra(RegisterActivity.EXTRA_PLATFORM_KEY, BuildConfig.DEFAULT_PLATFORM_KEY);
        intent.putExtra(RegisterActivity.EXTRA_APP_KEY, BuildConfig.DEFAULT_APP_KEY);
        startActivity(intent);
    }

    private void clearErrors() {
        binding.serverLayout.setError(null);
        binding.platformKeyLayout.setError(null);
        binding.appKeyLayout.setError(null);
        binding.accountLayout.setError(null);
        binding.passwordLayout.setError(null);
    }

    private void showConnectionConfigurationError() {
        Snackbar.make(
            binding.getRoot(),
            R.string.login_connection_config_error,
            Snackbar.LENGTH_LONG
        ).show();
    }

    private void setAuthenticationEntryEnabled(boolean enabled) {
        binding.accountInput.setEnabled(enabled);
        binding.passwordInput.setEnabled(enabled);
        binding.loginButton.setEnabled(enabled);
        binding.cardLoginButton.setEnabled(enabled);
        binding.cardAutoLoginButton.setEnabled(enabled);
        binding.registerButton.setEnabled(enabled);
        binding.forgotPasswordButton.setEnabled(enabled);
        binding.roleToggle.setEnabled(enabled);
    }

    private void setLoading(boolean loading) {
        boolean enabled = !loading && buildIdentityValid;
        binding.progress.setVisibility(loading ? View.VISIBLE : View.INVISIBLE);
        binding.loginButton.setEnabled(enabled);
        binding.cardLoginButton.setEnabled(enabled);
        binding.cardAutoLoginButton.setEnabled(enabled);
        binding.registerButton.setEnabled(enabled);
        binding.forgotPasswordButton.setEnabled(enabled);
        if (binding.roleToggle.getVisibility() == View.VISIBLE) binding.roleToggle.setEnabled(enabled);
    }

    private void openMain() {
        startActivity(new Intent(this, MainActivity.class).addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP));
        finish();
    }

    private void constrainFormWidth() {
        int available = binding.scroll.getWidth() - dp(24);
        int width = Math.min(available, dp(520));
        android.widget.FrameLayout.LayoutParams params = new android.widget.FrameLayout.LayoutParams(width, -2);
        params.gravity = android.view.Gravity.CENTER_HORIZONTAL;
        binding.formContainer.setLayoutParams(params);
    }

    private int dp(int value) {
        return Math.round(value * getResources().getDisplayMetrics().density);
    }

    private static String text(CharSequence value) {
        return value == null ? "" : value.toString().trim();
    }

    private static boolean booleanValue(JsonObject object, String key) {
        if (object == null || !object.has(key) || object.get(key).isJsonNull()) return false;
        try { return object.get(key).getAsBoolean(); }
        catch (RuntimeException ignored) { return false; }
    }

    @Override
    protected void onDestroy() {
        if (request != null) request.cancel();
        super.onDestroy();
    }
}
