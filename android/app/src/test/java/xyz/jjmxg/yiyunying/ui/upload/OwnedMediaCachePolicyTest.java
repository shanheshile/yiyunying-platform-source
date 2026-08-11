package xyz.jjmxg.yiyunying.ui.upload;

import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;

import java.io.File;
import java.util.Collections;

import org.junit.Rule;
import org.junit.Test;
import org.junit.rules.TemporaryFolder;

public final class OwnedMediaCachePolicyTest {
    @Rule public final TemporaryFolder temporary = new TemporaryFolder();

    @Test public void ownershipRequiresExactDirectoryAndApprovedPrefix() throws Exception {
        File cache = temporary.newFolder("cache");
        File captures = directory(cache, "captures");
        File optimized = directory(cache, "media_optimized");

        assertTrue(OwnedMediaCachePolicy.isOwned(cache, file(captures, "photo_1.jpg")));
        assertTrue(OwnedMediaCachePolicy.isOwned(cache, file(captures, "video_1.mp4")));
        assertTrue(OwnedMediaCachePolicy.isOwned(cache, file(captures, "app_photo_1.jpg")));
        assertTrue(OwnedMediaCachePolicy.isOwned(cache, file(captures, "app_video_1.mp4")));
        assertTrue(OwnedMediaCachePolicy.isOwned(cache, file(optimized, "photo_2.jpg")));
        assertTrue(OwnedMediaCachePolicy.isOwned(cache, file(optimized, "video_2.mp4")));
        assertTrue(OwnedMediaCachePolicy.isOwned(cache, file(optimized, "app_photo_2.jpg")));
        assertTrue(OwnedMediaCachePolicy.isOwned(cache, file(optimized, "app_video_2.mp4")));

        assertFalse(OwnedMediaCachePolicy.isOwned(cache, file(captures, "other.jpg")));
        assertFalse(OwnedMediaCachePolicy.isOwned(cache, file(cache, "photo_root.jpg")));
        File nested = directory(captures, "nested");
        assertFalse(OwnedMediaCachePolicy.isOwned(cache, file(nested, "photo_nested.jpg")));
        File aliasedTarget = file(optimized, "photo_alias.jpg");
        File aliasedPath = new File(captures, "../media_optimized/" + aliasedTarget.getName());
        assertFalse(OwnedMediaCachePolicy.isOwned(cache, aliasedPath));
        assertFalse(OwnedMediaCachePolicy.isOwned(cache,
            file(temporary.newFolder("external"), "photo_external.jpg")));
    }

    @Test public void contentAndMissingSchemesAreNeverDeletable() {
        assertTrue(OwnedMediaCachePolicy.acceptsUriScheme("file"));
        assertTrue(OwnedMediaCachePolicy.acceptsUriScheme("FILE"));
        assertFalse(OwnedMediaCachePolicy.acceptsUriScheme("content"));
        assertFalse(OwnedMediaCachePolicy.acceptsUriScheme("https"));
        assertFalse(OwnedMediaCachePolicy.acceptsUriScheme(null));
    }

    @Test public void expiryKeepsYoungAndExplicitlyActiveOwnedFiles() throws Exception {
        File cache = temporary.newFolder("expiry-cache");
        File captures = directory(cache, "captures");
        File stale = file(captures, "app_photo_stale.jpg");
        long now = 10_000L;
        assertTrue(stale.setLastModified(1_000L));
        String active = OwnedMediaCachePolicy.canonicalOwnedPath(cache, stale);

        assertTrue(OwnedMediaCachePolicy.shouldDeleteExpired(
            cache, stale, now, 5_000L, Collections.emptySet()));
        assertFalse(OwnedMediaCachePolicy.shouldDeleteExpired(
            cache, stale, now, 5_000L, Collections.singleton(active)));
        assertFalse(OwnedMediaCachePolicy.shouldDeleteExpired(
            cache, stale, now, 10_000L, Collections.emptySet()));
    }

    private static File directory(File parent, String name) {
        File directory = new File(parent, name);
        assertTrue(directory.mkdirs());
        return directory;
    }

    private static File file(File parent, String name) throws Exception {
        File file = new File(parent, name);
        assertTrue(file.createNewFile());
        return file;
    }
}
