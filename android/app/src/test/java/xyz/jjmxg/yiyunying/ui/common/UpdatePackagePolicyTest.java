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
            "ABCDEF",
            "xyz.jjmxg.yiyunying.user",
            55L,
            1024L,
            "abcdef"
        ));
    }

    @Test
    public void staleOrIncompletePackageCannotBeReused() {
        assertFalse(UpdatePackagePolicy.matches(
            "xyz.jjmxg.yiyunying.user", 55L, 1024L, "abcdef",
            "xyz.jjmxg.yiyunying.user", 54L, 1024L, "abcdef"));
        assertFalse(UpdatePackagePolicy.matches(
            "xyz.jjmxg.yiyunying.user", 55L, 1024L, "",
            "xyz.jjmxg.yiyunying.user", 55L, 1024L, "abcdef"));
        assertFalse(UpdatePackagePolicy.matches(
            "xyz.jjmxg.yiyunying.user", 55L, 1024L, "abcdef",
            "xyz.jjmxg.yiyunying.user", 55L, 1023L, "abcdef"));
    }
}
