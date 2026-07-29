package xyz.jjmxg.yiyunying.core;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertNotNull;
import static org.junit.Assert.assertTrue;

import android.content.ClipData;
import android.content.ClipboardManager;
import android.content.Context;

import androidx.test.core.app.ApplicationProvider;

import org.junit.Test;
import org.junit.runner.RunWith;
import org.robolectric.RobolectricTestRunner;
import org.robolectric.annotation.Config;

import xyz.jjmxg.yiyunying.YiyunyingApplication;

@RunWith(RobolectricTestRunner.class)
@Config(sdk = 34, application = YiyunyingApplication.class)
public final class CrashReporterTest {
    @Test
    public void diagnosticCanBeCopiedWithoutReplacingTheOriginalFailure() {
        Context context = ApplicationProvider.getApplicationContext();
        ClipboardManager clipboard =
            (ClipboardManager) context.getSystemService(Context.CLIPBOARD_SERVICE);
        assertNotNull(clipboard);

        String report = "测试错误信息\njava.lang.IllegalStateException: 示例";
        assertTrue(CrashReporter.copyToClipboard(report));

        ClipData copied = clipboard.getPrimaryClip();
        assertNotNull(copied);
        assertTrue(copied.getItemCount() > 0);
        assertEquals(report, copied.getItemAt(0).coerceToText(context).toString());
    }
}
