package xyz.jjmxg.yiyunying.ui.common;

import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;

import org.junit.Test;

public class UpdatePackagePolicyTest {
    @Test
    public void exactDownloadedPackageCanBeReused() {
        assertTrue(UpdatePackagePolicy.matches(
            "xyz.jjmxg.yiyunying.user",
            55L,
            1024L,
            "AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA",
            "xyz.jjmxg.yiyunying.user",
            55L,
            1024L,
            "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
        ));
    }

    @Test
    public void staleOrIncompletePackageCannotBeReused() {
        assertFalse(UpdatePackagePolicy.matches(
            "xyz.jjmxg.yiyunying.user", 55L, 1024L, hash('a'),
            "xyz.jjmxg.yiyunying.user", 54L, 1024L, hash('a')));
        assertFalse(UpdatePackagePolicy.matches(
            "xyz.jjmxg.yiyunying.user", 55L, 1024L, "",
            "xyz.jjmxg.yiyunying.user", 55L, 1024L, hash('a')));
        assertFalse(UpdatePackagePolicy.matches(
            "xyz.jjmxg.yiyunying.user", 55L, 1024L, hash('a'),
            "xyz.jjmxg.yiyunying.user", 55L, 1023L, hash('a')));
    }

    @Test public void metadataAndSignerMustBeCryptographicallyComplete() {
        assertTrue(UpdatePackagePolicy.validMetadata(
            "xyz.jjmxg.yiyunying.user.debug", 59L, 4096L, hash('f')));
        assertFalse(UpdatePackagePolicy.validMetadata(
            "xyz.jjmxg.yiyunying.user.debug", 59L, 4096L, "abcdef"));
        assertFalse(UpdatePackagePolicy.validMetadata(
            "not a package", 59L, 4096L, hash('f')));
    }

    private static String hash(char value) {
        StringBuilder result = new StringBuilder(64);
        for (int index = 0; index < 64; index++) result.append(value);
        return result.toString();
    }
}
