package xyz.jjmxg.yiyunying.ui.upload;

import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;

import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;

import org.junit.Test;

public final class OwnedMediaCacheContractTest {
    @Test public void chatLifecycleReleasesOnlyThroughTheOwnedCacheGuard() throws Exception {
        String chat = read(
            "src/main/java/xyz/jjmxg/yiyunying/ui/chat/ChatActivity.java");
        assertTrue(chat.contains("LocalMediaOptimizer.resolveOwnedCaptureFile(this, uri)"));
        assertTrue(chat.contains("result.optimized && ownedCaptureFile != null"));
        assertTrue(chat.contains("deleteOwnedAttachments(submittedAttachments)"));
        assertTrue(chat.contains("deleteOwnedAttachments(pendingAttachments)"));
        assertTrue(chat.contains("deleteOwnedAttachment(removed)"));
        assertTrue(chat.contains("deleteOwnedUris(candidates)"));
        assertTrue(chat.contains("LocalMediaOptimizer.cleanupExpiredOwnedCache("));
        assertFalse(chat.contains("pendingAttachments.removeIf"));
        String discardCapture = between(chat,
            "private void discardCaptureTarget()",
            "private void openMediaPicker()");
        int galleryGuard = discardCapture.indexOf("cameraTargetInGallery && cameraUri != null");
        int pendingRowDelete = discardCapture.indexOf(
            "getContentResolver().delete(cameraUri, null, null)");
        assertTrue(galleryGuard >= 0);
        assertTrue(pendingRowDelete > galleryGuard);
        assertFalse(between(chat,
            "private void deleteOwnedAttachment(",
            "private boolean containsUri(").contains("getContentResolver().delete("));

        String optimizer = read(
            "src/main/java/xyz/jjmxg/yiyunying/ui/upload/LocalMediaOptimizer.java");
        assertTrue(optimizer.contains("OwnedMediaCachePolicy.acceptsUriScheme(uri.getScheme())"));
        assertTrue(optimizer.contains("OwnedMediaCachePolicy.shouldDeleteExpired("));
        assertFalse(optimizer.contains("getContentResolver().delete("));
    }

    private static String read(String relative) throws Exception {
        Path direct = Path.of(relative);
        Path path = Files.exists(direct) ? direct : Path.of("app").resolve(relative);
        return new String(Files.readAllBytes(path), StandardCharsets.UTF_8);
    }

    private static String between(String source, String start, String end) {
        int from = source.indexOf(start);
        int to = source.indexOf(end, from + start.length());
        assertTrue(from >= 0);
        assertTrue(to > from);
        return source.substring(from, to);
    }
}
