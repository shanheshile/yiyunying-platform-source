package xyz.jjmxg.yiyunying.data.session;

import org.junit.Test;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertThrows;

public class EndpointPolicyTest {
    @Test
    public void normalizesHostAndTrailingSlash() {
        assertEquals("http://appht.jjmxg.xyz/", EndpointPolicy.normalize("appht.jjmxg.xyz"));
        assertEquals("https://example.com/api/", EndpointPolicy.normalize("https://example.com/api"));
    }

    @Test
    public void rejectsUnsafeOrAmbiguousUrls() {
        assertThrows(IllegalArgumentException.class, () -> EndpointPolicy.normalize("file:///tmp/api"));
        assertThrows(IllegalArgumentException.class, () -> EndpointPolicy.normalize("https://user@example.com"));
        assertThrows(IllegalArgumentException.class, () -> EndpointPolicy.normalize("https://example.com?a=1"));
        assertThrows(IllegalArgumentException.class, () -> EndpointPolicy.normalize(""));
    }
}
