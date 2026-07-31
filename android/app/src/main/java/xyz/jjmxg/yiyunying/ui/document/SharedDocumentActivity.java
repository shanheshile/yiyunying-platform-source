package xyz.jjmxg.yiyunying.ui.document;

import android.content.ClipData;
import android.content.ClipboardManager;
import android.content.Context;
import android.content.Intent;
import android.os.Bundle;
import android.view.Gravity;
import android.view.View;
import android.view.ViewGroup;
import android.widget.LinearLayout;
import android.widget.EditText;
import android.widget.TextView;

import androidx.appcompat.app.AppCompatActivity;

import com.google.android.material.button.MaterialButton;
import com.google.android.material.card.MaterialCardView;
import xyz.jjmxg.yiyunying.ui.common.YiyunyingDialogBuilder;
import com.google.android.material.snackbar.Snackbar;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;

import java.util.LinkedHashMap;
import java.util.Map;

import okhttp3.HttpUrl;
import xyz.jjmxg.yiyunying.core.AppAccess;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.data.api.RequestHandle;
import xyz.jjmxg.yiyunying.databinding.ActivitySharedDocumentBinding;
import xyz.jjmxg.yiyunying.domain.document.ShareCodeParser;
import xyz.jjmxg.yiyunying.ui.upload.FilePreviewActivity;

public final class SharedDocumentActivity extends xyz.jjmxg.yiyunying.ui.common.SystemInsetActivity {
    public static final String EXTRA_SHARE_CODE = "share_code";

    private ActivitySharedDocumentBinding binding;
    private RequestHandle request;
    private String shareCode;
    private String shareLink;
    private String password = "";
    private boolean passwordPrompted;

    public static void open(Context context, String code) {
        String parsed = ShareCodeParser.parse(code, true);
        if (parsed.isEmpty()) return;
        context.startActivity(new Intent(context, SharedDocumentActivity.class)
            .putExtra(EXTRA_SHARE_CODE, parsed));
    }

    @Override protected void onCreate(Bundle state) {
        super.onCreate(state);
        shareCode = ShareCodeParser.parse(getIntent().getStringExtra(EXTRA_SHARE_CODE), true);
        if (shareCode.isEmpty()) {
            finish();
            return;
        }
        binding = ActivitySharedDocumentBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());
        binding.toolbar.setNavigationOnClickListener(view -> finish());
        binding.copyCodeButton.setOnClickListener(view -> copy("分享码", shareCode));
        binding.copyLinkButton.setOnClickListener(view -> copy("分享链接", shareLink));
        binding.retryButton.setOnClickListener(view -> load());
        shareLink = buildShareLink();
        binding.shareCode.setText("分享码  " + shareCode);
        load();
    }

    private void load() {
        binding.progress.setVisibility(View.VISIBLE);
        binding.errorState.setVisibility(View.GONE);
        Map<String, String> query = new LinkedHashMap<>();
        if (!password.isEmpty()) query.put("password", password);
        request = AppAccess.from(this).repository().getPublic(
            "/api/public/note-shares/" + shareCode,
            query,
            result -> {
                if (binding == null) return;
                binding.progress.setVisibility(View.INVISIBLE);
                if (!result.isSuccessful()) {
                    boolean needsPassword = result.code() == 403
                        && result.dataObject().has("password_required")
                        && result.dataObject().get("password_required").getAsBoolean();
                    if (needsPassword && !passwordPrompted) {
                        passwordPrompted = true;
                        promptPassword();
                        return;
                    }
                    binding.contentScroll.setVisibility(View.GONE);
                    binding.errorState.setVisibility(View.VISIBLE);
                    binding.errorMessage.setText(result.message().isEmpty() ? "分享笔记加载失败" : result.message());
                    return;
                }
                JsonObject document = Jsons.object(result.dataObject(), "document");
                binding.title.setText(Jsons.string(document, "title"));
                binding.content.setText(Jsons.string(document, "content"));
                renderAttachments(document);
                String author = Jsons.string(document, "author_name");
                String appName = Jsons.string(document, "app_name");
                String updated = Jsons.string(document, "updated_at");
                binding.metadata.setText((author.isEmpty() ? "匿名作者" : author)
                    + " · " + appName + " · 更新于 " + updated);
                binding.contentScroll.setVisibility(View.VISIBLE);
                binding.errorState.setVisibility(View.GONE);
            }
        );
    }

    private void renderAttachments(JsonObject document) {
        xyz.jjmxg.yiyunying.ui.common.MediaViewRenderer.render(
            this, binding.attachmentContainer, Jsons.array(document, "attachments"));
        boolean visible = binding.attachmentContainer.getVisibility() == View.VISIBLE
            && binding.attachmentContainer.getChildCount() > 0;
        binding.attachmentTitle.setVisibility(visible ? View.VISIBLE : View.GONE);
    }

    private int dp(int value) {
        return Math.round(value * getResources().getDisplayMetrics().density);
    }

    private void promptPassword() {
        EditText input = new EditText(this);
        input.setHint("访问密码");
        input.setSingleLine(true);
        input.setInputType(android.text.InputType.TYPE_CLASS_TEXT | android.text.InputType.TYPE_TEXT_VARIATION_PASSWORD);
        int padding = Math.round(20 * getResources().getDisplayMetrics().density);
        android.widget.FrameLayout frame = new android.widget.FrameLayout(this);
        frame.setPadding(padding, 0, padding, 0);
        frame.addView(input, new android.widget.FrameLayout.LayoutParams(-1, -2));
        androidx.appcompat.app.AlertDialog dialog = new YiyunyingDialogBuilder(this)
            .setTitle("输入分享密码")
            .setView(frame)
            .setNegativeButton("取消", (ignored, which) -> showPasswordCancelled())
            .setPositiveButton("打开", null)
            .create();
        dialog.setOnShowListener(ignored -> dialog.getButton(androidx.appcompat.app.AlertDialog.BUTTON_POSITIVE)
            .setOnClickListener(view -> {
                String value = input.getText().toString();
                if (value.isEmpty()) {
                    input.setError("请输入访问密码");
                    return;
                }
                password = value;
                passwordPrompted = false;
                dialog.dismiss();
                load();
            }));
        dialog.show();
    }

    private void showPasswordCancelled() {
        binding.errorState.setVisibility(View.VISIBLE);
        binding.errorMessage.setText("该分享需要访问密码");
    }

    private String buildShareLink() {
        try {
            HttpUrl base = HttpUrl.get(AppAccess.from(this).session().baseUrl());
            HttpUrl resolved = base.resolve("api/public/note-shares/" + shareCode);
            return resolved == null ? shareCode : resolved.toString();
        } catch (RuntimeException ignored) {
            return shareCode;
        }
    }

    private void copy(String label, String value) {
        ClipboardManager manager = (ClipboardManager) getSystemService(Context.CLIPBOARD_SERVICE);
        if (manager != null) manager.setPrimaryClip(ClipData.newPlainText(label, value));
        Snackbar.make(binding.getRoot(), label + "已复制", Snackbar.LENGTH_SHORT).show();
    }

    @Override protected void onDestroy() {
        if (request != null) request.cancel();
        binding = null;
        super.onDestroy();
    }
}
