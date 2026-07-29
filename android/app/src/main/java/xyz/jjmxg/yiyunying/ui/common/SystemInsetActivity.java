package xyz.jjmxg.yiyunying.ui.common;

import android.app.Activity;
import android.os.Bundle;
import android.os.Build;
import android.content.Context;
import android.content.SharedPreferences;
import android.content.res.Configuration;
import android.view.View;
import android.view.ViewGroup;
import android.widget.EditText;
import android.widget.TextView;

import androidx.annotation.Nullable;
import androidx.appcompat.app.AppCompatActivity;
import androidx.appcompat.widget.Toolbar;
import androidx.core.graphics.Insets;
import androidx.core.view.ViewCompat;
import androidx.core.view.WindowCompat;
import androidx.core.view.WindowInsetsCompat;
import androidx.core.view.WindowInsetsControllerCompat;

import com.google.android.material.bottomnavigation.BottomNavigationView;
import com.google.android.material.textfield.TextInputLayout;

import java.util.ArrayList;
import java.util.Map;
import java.util.WeakHashMap;

import xyz.jjmxg.yiyunying.core.AppearanceStyleStore;
import xyz.jjmxg.yiyunying.core.RuntimeLanguage;

public abstract class SystemInsetActivity extends AppCompatActivity {
    private String appliedAccent = AppearanceStyleStore.BLUE;
    private String appliedLanguage = "zh-CN";
    private String appliedFont = AppearanceStyleStore.FONT_SYSTEM;
    private boolean appearanceRecreateQueued;
    private boolean appearanceRefreshQueued;
    private View appearanceRoot;
    private final Map<View, Boolean> observedAppearanceViews = new WeakHashMap<>();
    private final Map<View, Integer> appearanceSignatures = new WeakHashMap<>();
    private final View.OnLayoutChangeListener appearanceNodeLayoutListener =
        (view, left, top, right, bottom, oldLeft, oldTop, oldRight, oldBottom) ->
            onAppearanceNodeLayout(view);
    private final SharedPreferences.OnSharedPreferenceChangeListener appearanceListener =
        (preferences, key) -> onAppearanceChanged(key);

    protected boolean usePlatformDecorInsets() {
        return false;
    }

    protected boolean includeImeInsetsInRootPadding() {
        return true;
    }

    @Override protected void onCreate(@Nullable Bundle state) {
        appliedAccent = AppearanceStyleStore.accent(this);
        appliedLanguage = RuntimeLanguage.language(this);
        appliedFont = AppearanceStyleStore.font(this);
        AppearanceStyleStore.applyAccent(this);
        AppearanceStyleStore.applyFontTheme(this);
        WindowCompat.setDecorFitsSystemWindows(getWindow(), usePlatformDecorInsets());
        super.onCreate(state);
        suppressAppearanceTransitions();
        showSystemBars();
    }

    @Override protected void onStart() {
        super.onStart();
        getSharedPreferences(AppearanceStyleStore.PREFERENCES, Context.MODE_PRIVATE)
            .registerOnSharedPreferenceChangeListener(appearanceListener);
        ensureAppearanceIsCurrent();
    }

    @Override protected void onStop() {
        getSharedPreferences(AppearanceStyleStore.PREFERENCES, Context.MODE_PRIVATE)
            .unregisterOnSharedPreferenceChangeListener(appearanceListener);
        super.onStop();
    }

    @Override protected void onResume() {
        super.onResume();
        if (ensureAppearanceIsCurrent()) return;
        showSystemBars();
        View content = findViewById(android.R.id.content);
        if (content != null) content.post(() -> {
            applyRuntimeAppearance(content);
            ViewCompat.requestApplyInsets(content);
        });
    }

    @Override public void setContentView(View view) {
        super.setContentView(view);
        installAppearanceObserver(view);
        view.post(() -> {
            applyRuntimeAppearance(view);
            refreshAppearanceSignatures(view);
        });
        if (usePlatformDecorInsets()) return;
        int left = view.getPaddingLeft();
        int top = view.getPaddingTop();
        int right = view.getPaddingRight();
        int bottom = view.getPaddingBottom();
        ViewCompat.setOnApplyWindowInsetsListener(view, (target, windowInsets) -> {
            // Stable insets are required here. On several Android 15/16 vendor builds the
            // first visible-inset pass reports a zero-height status bar while it is animating,
            // which moves the toolbar underneath the cutout until the activity is recreated.
            Insets status = windowInsets.getInsetsIgnoringVisibility(WindowInsetsCompat.Type.statusBars());
            Insets navigation = windowInsets.getInsetsIgnoringVisibility(WindowInsetsCompat.Type.navigationBars());
            Insets cutout = windowInsets.getInsetsIgnoringVisibility(WindowInsetsCompat.Type.displayCutout());
            Insets ime = windowInsets.getInsets(WindowInsetsCompat.Type.ime());
            int safeLeft = Math.max(Math.max(status.left, navigation.left), cutout.left);
            int safeTop = Math.max(status.top, cutout.top);
            int safeRight = Math.max(Math.max(status.right, navigation.right), cutout.right);
            int safeBottom = Math.max(navigation.bottom, cutout.bottom);
            // Android 15/16 vendor builds can report a non-zero but still undersized
            // first-frame inset. Always keep portrait content below the physical status
            // area instead of allowing the search/header row to sit under a cutout.
            if (getResources().getConfiguration().orientation == Configuration.ORIENTATION_PORTRAIT) {
                safeTop = Math.max(safeTop, systemDimension("status_bar_height"));
            }
            int targetLeft = left + safeLeft;
            int targetTop = top + safeTop;
            int targetRight = right + safeRight;
            int targetBottom = bottom
                + (includeImeInsetsInRootPadding() ? Math.max(safeBottom, ime.bottom) : safeBottom);
            if (target.getPaddingLeft() != targetLeft || target.getPaddingTop() != targetTop
                || target.getPaddingRight() != targetRight || target.getPaddingBottom() != targetBottom) {
                target.setPadding(targetLeft, targetTop, targetRight, targetBottom);
            }
            return windowInsets;
        });
        view.post(() -> {
            showSystemBars();
            ViewCompat.requestApplyInsets(view);
        });
    }

    private void showSystemBars() {
        if (usePlatformDecorInsets()) return;
        WindowInsetsControllerCompat controller = WindowCompat.getInsetsController(
            getWindow(), getWindow().getDecorView());
        controller.show(WindowInsetsCompat.Type.systemBars());
    }

    private int systemDimension(String name) {
        int identifier = getResources().getIdentifier(name, "dimen", "android");
        return identifier == 0 ? 0 : getResources().getDimensionPixelSize(identifier);
    }

    /** Called after a shared appearance value changes while this page is visible. */
    protected void onAppearancePreferenceChanged(String key) { }

    private void onAppearanceChanged(String key) {
        if (AppearanceStyleStore.KEY_ACCENT.equals(key)
            || AppearanceStyleStore.KEY_FONT.equals(key)) {
            ensureAppearanceIsCurrent();
        } else if (AppearanceStyleStore.KEY_LANGUAGE.equals(key)) {
            // AppCompat owns the locale recreation. Refresh the current tree immediately
            // and mark the locale current to avoid a second, visible recreation.
            appliedLanguage = RuntimeLanguage.language(this);
            requestRuntimeAppearanceRefresh();
        } else {
            requestRuntimeAppearanceRefresh();
        }
        onAppearancePreferenceChanged(key);
    }

    private void applyRuntimeAppearance(View root) {
        if (!AppearanceStyleStore.FONT_SYSTEM.equals(AppearanceStyleStore.font(this))) {
            AppearanceStyleStore.applyFontTree(this, root);
        }
        // Always normalize visible text. RuntimeLanguage first maps previously rendered
        // English/Japanese back to canonical Chinese, then applies the selected locale.
        // This is what makes switching back to Chinese work without reopening each page.
        RuntimeLanguage.applyTree(this, root);
    }

    private boolean ensureAppearanceIsCurrent() {
        String accent = AppearanceStyleStore.accent(this);
        String language = RuntimeLanguage.language(this);
        String font = AppearanceStyleStore.font(this);
        boolean changed = !accent.equals(appliedAccent)
            || !language.equals(appliedLanguage)
            || !font.equals(appliedFont);
        if (changed) scheduleAppearanceRecreate();
        return changed;
    }

    private void installAppearanceObserver(View root) {
        removeAppearanceObserver();
        appearanceRoot = root;
        observeAppearanceSubtree(root);
    }

    private void removeAppearanceObserver() {
        for (View view : new ArrayList<>(observedAppearanceViews.keySet())) {
            if (view != null) view.removeOnLayoutChangeListener(appearanceNodeLayoutListener);
        }
        observedAppearanceViews.clear();
        appearanceSignatures.clear();
        appearanceRoot = null;
    }

    /**
     * Watches only nodes that actually changed instead of walking the complete screen on
     * every layout frame. This keeps server-backed rows and fragment content localized while
     * avoiding the frame drops caused by the former 240 ms full-tree polling pass.
     */
    private void observeAppearanceSubtree(View root) {
        if (root == null || observedAppearanceViews.put(root, Boolean.TRUE) != null) return;
        // Containers tell us when async rows are inserted; only text-bearing leaves need
        // their own signature listener. Attaching a listener to every icon/spacer/media view
        // made large home and notes screens relayout continuously after an appearance change.
        boolean dynamicText = supportsDynamicText(root);
        if (root instanceof ViewGroup || dynamicText) {
            root.addOnLayoutChangeListener(appearanceNodeLayoutListener);
        }
        if (dynamicText) appearanceSignatures.put(root, appearanceSignature(root));
        if (!(root instanceof ViewGroup)) return;
        ViewGroup group = (ViewGroup) root;
        for (int index = 0; index < group.getChildCount(); index++) {
            observeAppearanceSubtree(group.getChildAt(index));
        }
    }

    private void onAppearanceNodeLayout(View view) {
        if (view instanceof ViewGroup) {
            ViewGroup group = (ViewGroup) view;
            for (int index = 0; index < group.getChildCount(); index++) {
                View child = group.getChildAt(index);
                if (observedAppearanceViews.containsKey(child)) continue;
                applyRuntimeAppearance(child);
                observeAppearanceSubtree(child);
                refreshAppearanceSignatures(child);
            }
        }
        if (!requiresDynamicAppearancePass() || !supportsDynamicText(view)) return;
        int signature = appearanceSignature(view);
        Integer previous = appearanceSignatures.get(view);
        if (previous != null && previous == signature) return;
        // EditText contents belong to the user and must never be translated while typing.
        if (!(view instanceof EditText)) RuntimeLanguage.applyTree(this, view);
        appearanceSignatures.put(view, appearanceSignature(view));
    }

    private boolean supportsDynamicText(View view) {
        return view instanceof TextView
            || view instanceof TextInputLayout
            || view instanceof Toolbar
            || view instanceof BottomNavigationView;
    }

    private int appearanceSignature(View view) {
        int signature = 17;
        if (view instanceof TextView) {
            TextView text = (TextView) view;
            signature = 31 * signature + sequenceHash(text.getText());
            signature = 31 * signature + sequenceHash(text.getHint());
        }
        if (view instanceof TextInputLayout) {
            signature = 31 * signature + sequenceHash(((TextInputLayout) view).getHint());
        }
        if (view instanceof Toolbar) {
            Toolbar toolbar = (Toolbar) view;
            signature = 31 * signature + sequenceHash(toolbar.getTitle());
            signature = 31 * signature + sequenceHash(toolbar.getSubtitle());
            for (int index = 0; index < toolbar.getMenu().size(); index++) {
                signature = 31 * signature + sequenceHash(toolbar.getMenu().getItem(index).getTitle());
            }
        }
        if (view instanceof BottomNavigationView) {
            BottomNavigationView navigation = (BottomNavigationView) view;
            for (int index = 0; index < navigation.getMenu().size(); index++) {
                signature = 31 * signature + sequenceHash(navigation.getMenu().getItem(index).getTitle());
            }
        }
        return signature;
    }

    private int sequenceHash(CharSequence value) {
        return value == null ? 0 : value.toString().hashCode();
    }

    private void refreshAppearanceSignatures(View root) {
        if (root == null) return;
        if (supportsDynamicText(root)) {
            appearanceSignatures.put(root, appearanceSignature(root));
        }
        if (!(root instanceof ViewGroup)) return;
        ViewGroup group = (ViewGroup) root;
        for (int index = 0; index < group.getChildCount(); index++) {
            refreshAppearanceSignatures(group.getChildAt(index));
        }
    }

    private void requestRuntimeAppearanceRefresh() {
        View root = appearanceRoot;
        if (root == null || appearanceRefreshQueued) return;
        appearanceRefreshQueued = true;
        root.post(() -> {
            appearanceRefreshQueued = false;
            if (root == appearanceRoot && !isFinishing() && !isDestroyed()) {
                applyRuntimeAppearance(root);
                refreshAppearanceSignatures(root);
            }
        });
    }

    private boolean requiresDynamicAppearancePass() {
        // Dynamic rows, dialog content and menu titles can be created after the initial
        // appearance pass in every locale, including Chinese after a locale switch.
        return true;
    }

    private void scheduleAppearanceRecreate() {
        if (appearanceRecreateQueued || isFinishing() || isDestroyed()) return;
        appearanceRecreateQueued = true;
        View decor = getWindow().getDecorView();
        decor.post(() -> {
            if (isFinishing() || isDestroyed()) return;
            suppressAppearanceTransitions();
            recreate();
            suppressAppearanceTransitions();
        });
    }

    @SuppressWarnings("deprecation")
    private void suppressAppearanceTransitions() {
        if (Build.VERSION.SDK_INT >= 34) {
            overrideActivityTransition(Activity.OVERRIDE_TRANSITION_OPEN, 0, 0);
            overrideActivityTransition(Activity.OVERRIDE_TRANSITION_CLOSE, 0, 0);
        }
        overridePendingTransition(0, 0);
    }

    @Override protected void onDestroy() {
        removeAppearanceObserver();
        super.onDestroy();
    }
}
