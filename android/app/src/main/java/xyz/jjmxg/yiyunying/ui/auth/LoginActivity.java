package xyz.jjmxg.yiyunying.ui.auth;

import android.content.Intent;
import android.os.Bundle;
import android.view.View;
import android.view.inputmethod.EditorInfo;
import android.widget.FrameLayout;
import android.widget.LinearLayout;

import androidx.appcompat.app.AppCompatActivity;
import androidx.appcompat.app.AlertDialog;

import xyz.jjmxg.yiyunying.ui.common.YiyunyingDialogBuilder;
import xyz.jjmxg.yiyunying.ui.common.SafeTextInput;
import com.google.android.material.snackbar.Snackbar;
import com.google.android.material.textfield.TextInputEditText;
import com.google.android.material.textfield.TextInputLayout;

import xyz.jjmxg.yiyunying.BuildConfig;
import xyz.jjmxg.yiyunying.core.AppAccess;
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

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        SessionManager session = AppAccess.from(this).session();
        if (session.isAuthenticated() && !session.isCompatibleWithEdition()) {
            session.clearAuthentication();
        }
        if (session.isCompatibleWithEdition() && !getIntent().getBooleanExtra(EXTRA_FORCE_LOGIN, false)) {
            openMain();
            return;
        }
        binding = ActivityLoginBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());
        CrashNotice.showPending(this);
        binding.serverInput.setText(session.baseUrl());
        binding.platformKeyInput.setText(session.platformKey());
        binding.appKeyInput.setText(session.appKey());
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
    }

    private void updateRole(Role role) {
        selectedRole = role;
        binding.serverLayout.setVisibility(role == Role.USER ? View.GONE : View.VISIBLE);
        binding.platformKeyLayout.setVisibility(role == Role.USER ? View.GONE : View.VISIBLE);
        binding.appKeyLayout.setVisibility(View.GONE);
        binding.registerButton.setVisibility(AppEdition.allowsSelfRegistration() ? View.VISIBLE : View.GONE);
        boolean userEdition = role == Role.USER;
        binding.forgotPasswordButton.setVisibility(userEdition ? View.VISIBLE : View.GONE);
        binding.cardLoginButton.setVisibility(userEdition ? View.VISIBLE : View.GONE);
        binding.cardAutoLoginButton.setVisibility(
            userEdition && AppAccess.from(this).session().hasCardBinding(text(binding.appKeyInput.getText()))
                ? View.VISIBLE : View.GONE
        );
        String account = text(binding.accountInput.getText());
        if (account.isEmpty() || "root".equals(account) || "admin".equals(account) || "user".equals(account)) {
            binding.accountInput.setText(AppEdition.defaultAccount());
            binding.accountInput.setSelection(binding.accountInput.length());
        }
    }

    private void login() {
        clearErrors();
        String server = text(binding.serverInput.getText());
        String platformKey = text(binding.platformKeyInput.getText());
        String appKey = text(binding.appKeyInput.getText());
        String account = text(binding.accountInput.getText());
        String password = binding.passwordInput.getText() == null ? "" : binding.passwordInput.getText().toString();
        try {
            EndpointPolicy.normalize(server);
        } catch (IllegalArgumentException exception) {
            if (selectedRole == Role.USER) {
                Snackbar.make(binding.getRoot(), "当前服务暂不可用，请稍后重试", Snackbar.LENGTH_LONG).show();
            } else {
                binding.serverLayout.setError(exception.getMessage());
            }
            return;
        }
        if (selectedRole != Role.USER && platformKey.isEmpty()) {
            binding.platformKeyLayout.setError("平台标识不能为空");
            return;
        }
        if (selectedRole == Role.USER && appKey.isEmpty()) {
            Snackbar.make(binding.getRoot(), "当前应用配置无效，请安装最新版本", Snackbar.LENGTH_LONG).show();
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
        String server = text(binding.serverInput.getText());
        String appKey = text(binding.appKeyInput.getText());
        try {
            EndpointPolicy.normalize(server);
        } catch (IllegalArgumentException exception) {
            Snackbar.make(binding.getRoot(), "当前服务暂不可用，请稍后重试", Snackbar.LENGTH_LONG).show();
            return;
        }
        if (appKey.isEmpty()) {
            Snackbar.make(binding.getRoot(), "当前应用配置无效，请安装最新版本", Snackbar.LENGTH_LONG).show();
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
        Intent intent = new Intent(this, RegisterActivity.class);
        intent.putExtra(RegisterActivity.EXTRA_ROLE, selectedRole.wireName());
        intent.putExtra(RegisterActivity.EXTRA_BASE_URL, text(binding.serverInput.getText()));
        intent.putExtra(RegisterActivity.EXTRA_PLATFORM_KEY, text(binding.platformKeyInput.getText()));
        intent.putExtra(RegisterActivity.EXTRA_APP_KEY, text(binding.appKeyInput.getText()));
        startActivity(intent);
    }

    private void clearErrors() {
        binding.serverLayout.setError(null);
        binding.platformKeyLayout.setError(null);
        binding.appKeyLayout.setError(null);
        binding.accountLayout.setError(null);
        binding.passwordLayout.setError(null);
    }

    private void setLoading(boolean loading) {
        binding.progress.setVisibility(loading ? View.VISIBLE : View.INVISIBLE);
        binding.loginButton.setEnabled(!loading);
        binding.cardLoginButton.setEnabled(!loading);
        binding.cardAutoLoginButton.setEnabled(!loading);
        binding.registerButton.setEnabled(!loading);
        binding.forgotPasswordButton.setEnabled(!loading);
        if (binding.roleToggle.getVisibility() == View.VISIBLE) binding.roleToggle.setEnabled(!loading);
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

    @Override
    protected void onDestroy() {
        if (request != null) request.cancel();
        super.onDestroy();
    }
}
