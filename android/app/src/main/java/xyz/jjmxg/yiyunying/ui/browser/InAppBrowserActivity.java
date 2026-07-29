package xyz.jjmxg.yiyunying.ui.browser;

import android.annotation.SuppressLint;
import android.content.Context;
import android.content.Intent;
import android.graphics.Bitmap;
import android.net.Uri;
import android.os.Bundle;
import android.view.Menu;
import android.view.MenuItem;
import android.view.View;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceRequest;
import android.webkit.WebView;
import android.webkit.WebViewClient;

import androidx.activity.OnBackPressedCallback;
import com.google.android.material.snackbar.Snackbar;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.databinding.ActivityInAppBrowserBinding;
import xyz.jjmxg.yiyunying.ui.common.SystemInsetActivity;

public final class InAppBrowserActivity extends SystemInsetActivity {
    private static final String EXTRA_URL = "url";
    private ActivityInAppBrowserBinding binding;
    private String initialUrl = "";

    public static void open(Context context, String url) {
        Intent intent = new Intent(context, InAppBrowserActivity.class).putExtra(EXTRA_URL, url);
        if (!(context instanceof android.app.Activity)) intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
        context.startActivity(intent);
    }

    @SuppressLint("SetJavaScriptEnabled")
    @Override protected void onCreate(Bundle state) {
        super.onCreate(state);
        binding = ActivityInAppBrowserBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());
        initialUrl = getIntent().getStringExtra(EXTRA_URL);
        if (!isWebUrl(initialUrl)) {
            finish();
            return;
        }
        binding.toolbar.setNavigationOnClickListener(view -> navigateBack());
        Menu menu = binding.toolbar.getMenu();
        MenuItem back = menu.add("后退").setIcon(R.drawable.ic_arrow_left);
        MenuItem forward = menu.add("前进").setIcon(R.drawable.ic_arrow_right);
        MenuItem refresh = menu.add("刷新").setIcon(R.drawable.ic_refresh);
        MenuItem external = menu.add("用其他浏览器打开");
        back.setShowAsAction(MenuItem.SHOW_AS_ACTION_ALWAYS);
        forward.setShowAsAction(MenuItem.SHOW_AS_ACTION_ALWAYS);
        refresh.setShowAsAction(MenuItem.SHOW_AS_ACTION_ALWAYS);
        external.setShowAsAction(MenuItem.SHOW_AS_ACTION_NEVER);
        binding.toolbar.setOnMenuItemClickListener(item -> {
            if (item == back) { if (binding.web.canGoBack()) binding.web.goBack(); return true; }
            if (item == forward) { if (binding.web.canGoForward()) binding.web.goForward(); return true; }
            if (item == refresh) { binding.web.reload(); return true; }
            openExternal(); return true;
        });

        binding.web.getSettings().setJavaScriptEnabled(true);
        binding.web.getSettings().setDomStorageEnabled(true);
        binding.web.getSettings().setAllowFileAccess(false);
        binding.web.getSettings().setAllowContentAccess(false);
        binding.web.getSettings().setBuiltInZoomControls(true);
        binding.web.getSettings().setDisplayZoomControls(false);
        binding.web.getSettings().setMediaPlaybackRequiresUserGesture(true);
        binding.web.setWebChromeClient(new WebChromeClient() {
            @Override public void onProgressChanged(WebView view, int progress) {
                if (binding == null) return;
                binding.progress.setProgressCompat(progress, true);
                binding.progress.setVisibility(progress >= 100 ? View.INVISIBLE : View.VISIBLE);
            }

            @Override public void onReceivedTitle(WebView view, String title) {
                if (binding != null && title != null && !title.trim().isEmpty()) {
                    xyz.jjmxg.yiyunying.core.RuntimeLanguage.setDynamicToolbarTitle(binding.toolbar, title);
                }
            }
        });
        binding.web.setWebViewClient(new WebViewClient() {
            @Override public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
                String url = request.getUrl().toString();
                if (isWebUrl(url)) return false;
                return LinkNavigator.open(InAppBrowserActivity.this, url);
            }

            @Override public void onPageStarted(WebView view, String url, Bitmap favicon) {
                if (binding != null) binding.toolbar.setSubtitle(Uri.parse(url).getHost());
            }
        });
        getOnBackPressedDispatcher().addCallback(this, new OnBackPressedCallback(true) {
            @Override public void handleOnBackPressed() { navigateBack(); }
        });
        binding.web.loadUrl(initialUrl);
    }

    private void navigateBack() {
        if (binding != null && binding.web.canGoBack()) binding.web.goBack();
        else finish();
    }

    private void openExternal() {
        String url = binding == null ? initialUrl : binding.web.getUrl();
        try { startActivity(new Intent(Intent.ACTION_VIEW, Uri.parse(url))); }
        catch (RuntimeException ignored) { Snackbar.make(binding.getRoot(), "没有可用的外部浏览器", Snackbar.LENGTH_SHORT).show(); }
    }

    private static boolean isWebUrl(String url) {
        if (url == null) return false;
        String scheme = Uri.parse(url.trim()).getScheme();
        return "http".equalsIgnoreCase(scheme) || "https".equalsIgnoreCase(scheme);
    }

    @Override protected void onDestroy() {
        if (binding != null) {
            binding.web.stopLoading();
            binding.web.setWebChromeClient(null);
            binding.web.setWebViewClient(null);
            binding.web.destroy();
            binding = null;
        }
        super.onDestroy();
    }
}
