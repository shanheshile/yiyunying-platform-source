package xyz.jjmxg.yiyunying.ui.home;

import android.app.Activity;
import android.content.res.ColorStateList;
import android.view.View;
import android.widget.ImageButton;

import org.junit.Test;
import org.junit.runner.RunWith;
import org.robolectric.Robolectric;
import org.robolectric.RobolectricTestRunner;
import org.robolectric.android.controller.ActivityController;
import org.robolectric.annotation.Config;

import xyz.jjmxg.yiyunying.R;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertNotNull;
import static org.junit.Assert.assertTrue;

@RunWith(RobolectricTestRunner.class)
@Config(sdk = 35)
public final class HomeHeaderUiTest {
    @Test public void lightToolbarCreateActionMatchesNotesForeground() {
        assertHeaderTint(false);
    }

    @Test @Config(sdk = 35, qualifiers = "night")
    public void darkToolbarCreateActionUsesReadableMatchingForeground() {
        assertHeaderTint(true);
    }

    private static void assertHeaderTint(boolean dark) {
        ActivityController<Activity> controller = Robolectric.buildActivity(Activity.class);
        Activity activity = controller.get();
        activity.setTheme(R.style.Theme_Yiyunying);
        controller.setup();
        View root = activity.getLayoutInflater().inflate(R.layout.fragment_user_shell, null, false);
        ImageButton notes = root.findViewById(R.id.notesButton);
        ImageButton primary = root.findViewById(R.id.primaryAction);
        ColorStateList notesTint = notes.getImageTintList();
        ColorStateList primaryTint = primary.getImageTintList();
        assertNotNull(notesTint);
        assertNotNull(primaryTint);
        int expected = activity.getColor(R.color.home_header_action_tint);
        assertEquals(expected, notesTint.getDefaultColor());
        assertEquals(expected, primaryTint.getDefaultColor());
        int background = activity.getColor(R.color.surface);
        assertTrue(contrast(expected, background) >= (dark ? 7.0 : 4.5));
        controller.pause().stop().destroy();
    }

    private static double contrast(int foreground, int background) {
        double light = luminance(foreground);
        double dark = luminance(background);
        return (Math.max(light, dark) + 0.05) / (Math.min(light, dark) + 0.05);
    }

    private static double luminance(int color) {
        double red = channel((color >> 16) & 0xFF);
        double green = channel((color >> 8) & 0xFF);
        double blue = channel(color & 0xFF);
        return 0.2126 * red + 0.7152 * green + 0.0722 * blue;
    }

    private static double channel(int value) {
        double normalized = value / 255.0;
        return normalized <= 0.03928
            ? normalized / 12.92
            : Math.pow((normalized + 0.055) / 1.055, 2.4);
    }
}
