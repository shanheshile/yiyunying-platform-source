package xyz.jjmxg.yiyunying.ui.common;

import static org.junit.Assert.assertArrayEquals;
import static org.junit.Assert.assertEquals;
import static org.junit.Assert.fail;

import org.junit.Rule;
import org.junit.Test;
import org.junit.rules.TemporaryFolder;

import java.io.ByteArrayInputStream;
import java.io.File;
import java.nio.file.Files;

public class UpdateDownloadIoTest {
    @Rule public final TemporaryFolder temporary = new TemporaryFolder();

    @Test public void exactRangeIsAppendedWithoutDuplicatingExistingBytes() throws Exception {
        File part = temporary.newFile("release.part");
        Files.write(part.toPath(), new byte[] { 1, 2, 3 });
        long total = UpdateDownloadIo.copy(new ByteArrayInputStream(new byte[] { 4, 5 }),
            part, true, 3L, 5L, () -> false, null);
        assertEquals(5L, total);
        assertArrayEquals(new byte[] { 1, 2, 3, 4, 5 }, Files.readAllBytes(part.toPath()));
    }

    @Test public void cleanRestartTruncatesStalePartial() throws Exception {
        File part = temporary.newFile("release.part");
        Files.write(part.toPath(), new byte[] { 9, 9, 9 });
        UpdateDownloadIo.copy(new ByteArrayInputStream(new byte[] { 1, 2 }),
            part, false, 0L, 2L, () -> false, null);
        assertArrayEquals(new byte[] { 1, 2 }, Files.readAllBytes(part.toPath()));
    }

    @Test public void changedPartialLengthAndOversizedBodyFailClosed() throws Exception {
        File part = temporary.newFile("release.part");
        Files.write(part.toPath(), new byte[] { 1, 2 });
        try {
            UpdateDownloadIo.copy(new ByteArrayInputStream(new byte[] { 3 }),
                part, true, 1L, 3L, () -> false, null);
            fail("Expected stale partial rejection");
        } catch (java.io.IOException expected) {
            assertEquals("partial length changed before append", expected.getMessage());
        }
        try {
            UpdateDownloadIo.copy(new ByteArrayInputStream(new byte[] { 3, 4 }),
                part, true, 2L, 3L, () -> false, null);
            fail("Expected size limit rejection");
        } catch (UpdateDownloadIo.SizeLimitException expected) {
            // The caller removes the now-untrusted oversized part.
        }
    }
}
