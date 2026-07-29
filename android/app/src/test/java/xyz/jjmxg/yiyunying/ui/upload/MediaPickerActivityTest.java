package xyz.jjmxg.yiyunying.ui.upload;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;

import android.content.Intent;
import android.net.Uri;

import org.junit.Test;
import org.junit.runner.RunWith;
import org.robolectric.RobolectricTestRunner;
import org.robolectric.annotation.Config;

import java.util.ArrayList;

@RunWith(RobolectricTestRunner.class)
@Config(sdk = 34)
public final class MediaPickerActivityTest {
    @Test
    public void returningWithoutSendStillCarriesTheLiveSelectionBackToInlineAlbum() {
        ArrayList<Uri> selected = new ArrayList<>();
        selected.add(Uri.parse("content://media/images/11"));
        selected.add(Uri.parse("content://media/images/22"));

        Intent result = MediaPickerActivity.selectionResult(selected, true, "{}", false);
        ArrayList<Uri> returned = result.getParcelableArrayListExtra(
            MediaPickerActivity.EXTRA_SELECTED_URIS);

        assertEquals(selected, returned);
        assertTrue(result.getBooleanExtra(MediaPickerActivity.EXTRA_ORIGINAL, false));
        assertFalse(result.getBooleanExtra(MediaPickerActivity.EXTRA_SELECTION_CONFIRMED, true));
    }

    @Test
    public void confirmingSelectionUsesTheSameSelectionContract() {
        ArrayList<Uri> selected = new ArrayList<>();
        selected.add(Uri.parse("content://media/video/33"));

        Intent result = MediaPickerActivity.selectionResult(selected, false,
            "{\"content://media/video/33\":{}}", true);

        assertEquals(selected, result.getParcelableArrayListExtra(
            MediaPickerActivity.EXTRA_SELECTED_URIS));
        assertTrue(result.getBooleanExtra(MediaPickerActivity.EXTRA_SELECTION_CONFIRMED, false));
    }
}
