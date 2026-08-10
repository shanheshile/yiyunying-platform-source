package xyz.jjmxg.yiyunying.ui.common;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;

import org.junit.Test;

public class UpdateTransportPolicyTest {
    @Test public void blocksHttpsDowngradeAndNonHttpSchemes() {
        assertTrue(UpdateTransportPolicy.allows("https://example.test/a.apk", "https://cdn.test/a.apk"));
        assertTrue(UpdateTransportPolicy.allows("http://example.test/a.apk", "https://cdn.test/a.apk"));
        assertFalse(UpdateTransportPolicy.allows("https://example.test/a.apk", "http://cdn.test/a.apk"));
        assertFalse(UpdateTransportPolicy.allows("https://example.test/a.apk", "file:///tmp/a.apk"));
        assertFalse(UpdateTransportPolicy.allows(
            "http://example.test/a.apk", "http://cdn.test/a.apk", false));
        assertTrue(UpdateTransportPolicy.allows(
            "https://example.test/a.apk", "https://cdn.test/a.apk", false));
    }

    @Test public void usesOnlyStrongEntityTagForIfRange() {
        assertEquals("\"release-59\"", UpdateTransportPolicy.ifRange("\"release-59\"", "yesterday"));
        assertEquals("yesterday", UpdateTransportPolicy.ifRange("W/\"release-59\"", "yesterday"));
        assertEquals("", UpdateTransportPolicy.ifRange("", ""));
    }
}
