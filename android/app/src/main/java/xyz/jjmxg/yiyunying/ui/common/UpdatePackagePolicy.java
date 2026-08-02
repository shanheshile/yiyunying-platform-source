package xyz.jjmxg.yiyunying.ui.common;

import java.util.Locale;

final class UpdatePackagePolicy {
    private UpdatePackagePolicy() { }

    static boolean matches(
        String expectedPackage,
        long expectedVersionCode,
        long expectedSize,
        String expectedSha256,
        String actualPackage,
        long actualVersionCode,
        long actualSize,
        String actualSha256
    ) {
        String requiredPackage = value(expectedPackage);
        String requiredHash = hash(expectedSha256);
        if (requiredPackage.isEmpty() || expectedVersionCode <= 0L || expectedSize <= 0L
            || requiredHash.isEmpty()) {
            return false;
        }
        return requiredPackage.equals(value(actualPackage))
            && expectedVersionCode == actualVersionCode
            && expectedSize == actualSize
            && requiredHash.equals(hash(actualSha256));
    }

    private static String value(String value) {
        return value == null ? "" : value.trim();
    }

    private static String hash(String value) {
        return value(value).toLowerCase(Locale.ROOT);
    }
}
