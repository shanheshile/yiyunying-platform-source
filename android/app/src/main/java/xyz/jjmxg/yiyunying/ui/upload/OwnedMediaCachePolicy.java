package xyz.jjmxg.yiyunying.ui.upload;

import java.io.File;
import java.io.IOException;
import java.util.Set;

/** Pure ownership boundary for disposable camera and optimized-media cache files. */
final class OwnedMediaCachePolicy {
    static final String CAPTURE_DIRECTORY = "captures";
    static final String OPTIMIZED_DIRECTORY = "media_optimized";
    static final String PHOTO_PREFIX = "photo_";
    static final String VIDEO_PREFIX = "video_";
    static final String APP_PHOTO_PREFIX = "app_photo_";
    static final String APP_VIDEO_PREFIX = "app_video_";

    private OwnedMediaCachePolicy() { }

    static boolean acceptsUriScheme(String scheme) {
        return "file".equalsIgnoreCase(scheme == null ? "" : scheme.trim());
    }

    static boolean isOwned(File cacheDirectory, File candidate) {
        if (cacheDirectory == null || candidate == null) return false;
        try {
            File absoluteCache = cacheDirectory.getAbsoluteFile();
            File absoluteParent = candidate.getAbsoluteFile().getParentFile();
            if (absoluteParent == null
                || !absoluteParent.equals(new File(absoluteCache, CAPTURE_DIRECTORY).getAbsoluteFile())
                && !absoluteParent.equals(new File(absoluteCache, OPTIMIZED_DIRECTORY).getAbsoluteFile())) {
                return false;
            }
            File cache = cacheDirectory.getCanonicalFile();
            File target = candidate.getCanonicalFile();
            File parent = target.getParentFile();
            if (parent == null || !isAllowedDirectory(cache, parent)) return false;
            String name = target.getName();
            return name.startsWith(PHOTO_PREFIX) || name.startsWith(VIDEO_PREFIX)
                || name.startsWith(APP_PHOTO_PREFIX) || name.startsWith(APP_VIDEO_PREFIX);
        } catch (IOException | SecurityException ignored) {
            return false;
        }
    }

    static boolean isAllowedDirectory(File cacheDirectory, File candidateDirectory) {
        if (cacheDirectory == null || candidateDirectory == null) return false;
        try {
            File absoluteCache = cacheDirectory.getAbsoluteFile();
            File absoluteDirectory = candidateDirectory.getAbsoluteFile();
            if (!absoluteDirectory.equals(new File(absoluteCache, CAPTURE_DIRECTORY).getAbsoluteFile())
                && !absoluteDirectory.equals(new File(absoluteCache, OPTIMIZED_DIRECTORY).getAbsoluteFile())) {
                return false;
            }
            File cache = cacheDirectory.getCanonicalFile();
            File directory = candidateDirectory.getCanonicalFile();
            File parent = directory.getParentFile();
            if (parent == null || !parent.equals(cache)) return false;
            String name = directory.getName();
            return CAPTURE_DIRECTORY.equals(name) || OPTIMIZED_DIRECTORY.equals(name);
        } catch (IOException | SecurityException ignored) {
            return false;
        }
    }

    static String canonicalOwnedPath(File cacheDirectory, File candidate) {
        if (!isOwned(cacheDirectory, candidate)) return "";
        try {
            return candidate.getCanonicalPath();
        } catch (IOException | SecurityException ignored) {
            return "";
        }
    }

    static boolean shouldDeleteExpired(
        File cacheDirectory,
        File candidate,
        long nowMillis,
        long maxAgeMillis,
        Set<String> activeCanonicalPaths
    ) {
        String canonical = canonicalOwnedPath(cacheDirectory, candidate);
        if (canonical.isEmpty() || candidate == null || !candidate.isFile()) return false;
        if (activeCanonicalPaths != null && activeCanonicalPaths.contains(canonical)) return false;
        long age = nowMillis - candidate.lastModified();
        return maxAgeMillis >= 0L && age >= maxAgeMillis;
    }
}
