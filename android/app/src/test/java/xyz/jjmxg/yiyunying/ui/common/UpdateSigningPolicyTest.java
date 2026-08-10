package xyz.jjmxg.yiyunying.ui.common;

import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;

import java.util.Arrays;
import java.util.Collections;
import java.util.HashSet;
import java.util.Set;
import org.junit.Test;

public class UpdateSigningPolicyTest {
    @Test public void exactCurrentSignerIsAccepted() {
        assertTrue(UpdateSigningPolicy.allows(set(hash('a')), set(hash('a')), set(hash('a'))));
    }

    @Test public void forwardRotationRequiresCandidateProofHistory() {
        assertTrue(UpdateSigningPolicy.allows(
            set(hash('a')), set(hash('b')), set(hash('a'), hash('b'))));
        assertFalse(UpdateSigningPolicy.allows(
            set(hash('a')), set(hash('b')), set(hash('b'))));
    }

    @Test public void staleOldSignerCannotMatchInstalledHistoryBackwards() {
        // Installed current signer is b. A candidate currently signed only by old signer a is
        // rejected even if some unrelated caller could supply both values as "history".
        assertFalse(UpdateSigningPolicy.allows(
            set(hash('b')), set(hash('a')), set(hash('a'))));
    }

    @Test public void multipleSignerSetsMustMatchExactly() {
        assertTrue(UpdateSigningPolicy.allows(
            set(hash('a'), hash('b')), set(hash('a'), hash('b')), Collections.emptySet()));
        assertFalse(UpdateSigningPolicy.allows(
            set(hash('a'), hash('b')), set(hash('a')), set(hash('a'), hash('b'))));
    }

    private static String hash(char value) {
        StringBuilder result = new StringBuilder(64);
        for (int index = 0; index < 64; index++) result.append(value);
        return result.toString();
    }

    private static Set<String> set(String... values) {
        return new HashSet<>(Arrays.asList(values));
    }
}
