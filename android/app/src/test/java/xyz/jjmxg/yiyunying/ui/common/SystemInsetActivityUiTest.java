package xyz.jjmxg.yiyunying.ui.common;

import static org.junit.Assert.assertEquals;

import android.content.Context;
import android.graphics.drawable.ColorDrawable;
import android.os.Bundle;
import android.view.ContextThemeWrapper;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.widget.TextView;

import androidx.core.graphics.Insets;
import androidx.core.view.ViewCompat;
import androidx.core.view.WindowInsetsCompat;
import androidx.test.core.app.ApplicationProvider;

import org.junit.Test;
import org.junit.runner.RunWith;
import org.robolectric.Robolectric;
import org.robolectric.RobolectricTestRunner;
import org.robolectric.android.controller.ActivityController;
import org.robolectric.annotation.Config;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.YiyunyingApplication;

@RunWith(RobolectricTestRunner.class)
@Config(sdk = 35, application = YiyunyingApplication.class)
public final class SystemInsetActivityUiTest {
    @Test public void layoutResourceOverloadAppliesStableInsetsWithoutAccumulating() {
        try (ActivityController<LayoutResourceActivity> controller =
                 Robolectric.buildActivity(LayoutResourceActivity.class).setup()) {
            ViewGroup content = controller.get().findViewById(android.R.id.content);
            View root = content.getChildAt(content.getChildCount() - 1);
            WindowInsetsCompat insets = new WindowInsetsCompat.Builder()
                .setInsetsIgnoringVisibility(
                    WindowInsetsCompat.Type.statusBars(), Insets.of(0, 512, 0, 0))
                .setInsetsIgnoringVisibility(
                    WindowInsetsCompat.Type.navigationBars(), Insets.of(7, 0, 11, 80))
                .setInsetsIgnoringVisibility(
                    WindowInsetsCompat.Type.displayCutout(), Insets.of(17, 300, 19, 30))
                .build();

            ViewCompat.dispatchApplyWindowInsets(root, insets);
            assertEquals(17, root.getPaddingLeft());
            assertEquals(512, root.getPaddingTop());
            assertEquals(19, root.getPaddingRight());
            assertEquals(80, root.getPaddingBottom());

            ViewCompat.dispatchApplyWindowInsets(root, insets);
            assertEquals(17, root.getPaddingLeft());
            assertEquals(512, root.getPaddingTop());
            assertEquals(19, root.getPaddingRight());
            assertEquals(80, root.getPaddingBottom());
        }
    }

    @Test public void physicalStatusFallbackIsFullScreenOnly() {
        assertEquals(72, SystemInsetActivity.resolveSafeTop(0, 0, 72, true, false));
        assertEquals(0, SystemInsetActivity.resolveSafeTop(0, 0, 72, true, true));
        assertEquals(44, SystemInsetActivity.resolveSafeTop(44, 0, 72, true, true));
        assertEquals(60, SystemInsetActivity.resolveSafeTop(20, 60, 72, false, false));
    }

    @Test @Config(qualifiers = "notnight")
    public void profileAndConversationUseLightThemeForegrounds() {
        assertProfileAndConversationThemeColors();
    }

    @Test @Config(qualifiers = "night")
    public void profileAndConversationUseDarkThemeForegrounds() {
        assertProfileAndConversationThemeColors();
    }

    private void assertProfileAndConversationThemeColors() {
        Context context = new ContextThemeWrapper(
            ApplicationProvider.getApplicationContext(), R.style.Theme_Yiyunying);
        int surface = ThemeColors.resolve(context,
            com.google.android.material.R.attr.colorSurface, R.color.surface);
        int onSurfaceVariant = ThemeColors.resolve(context,
            com.google.android.material.R.attr.colorOnSurfaceVariant,
            R.color.on_surface_variant);

        View profile = LayoutInflater.from(context)
            .inflate(R.layout.activity_user_profile, null, false);
        assertEquals(surface, ((ColorDrawable) profile.getBackground()).getColor());
        assertEquals(onSurfaceVariant,
            ((TextView) profile.findViewById(R.id.account)).getCurrentTextColor());
        assertEquals(onSurfaceVariant,
            ((TextView) profile.findViewById(R.id.visibilityNotice)).getCurrentTextColor());

        View conversation = LayoutInflater.from(context)
            .inflate(R.layout.activity_conversation_permission, null, false);
        assertEquals(surface, ((ColorDrawable) conversation.getBackground()).getColor());
        assertEquals(onSurfaceVariant,
            ((TextView) conversation.findViewById(R.id.profile_subtitle)).getCurrentTextColor());
    }

    public static final class LayoutResourceActivity extends SystemInsetActivity {
        @Override protected void onCreate(Bundle state) {
            setTheme(R.style.Theme_Yiyunying);
            super.onCreate(state);
            // Regression path used by ConversationPermissionActivity.
            setContentView(R.layout.activity_conversation_permission);
        }
    }
}
