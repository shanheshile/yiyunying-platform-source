package xyz.jjmxg.yiyunying.data.session;

import org.junit.Test;

import java.util.Arrays;
import java.util.List;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertThrows;

public class EndpointPolicyTest {
    @Test
    public void normalizesHostAndTrailingSlash() {
        assertEquals("http://appht.jjmxg.xyz/", EndpointPolicy.normalize("appht.jjmxg.xyz"));
        assertEquals("https://example.com/api/", EndpointPolicy.normalize("https://example.com/api"));
    }

    @Test
    public void stableModeDefaultsToHttpsAndRejectsExplicitHttp() {
        assertEquals(
                "https://appht.jjmxg.xyz/",
                EndpointPolicy.normalize("appht.jjmxg.xyz", false));
        assertThrows(IllegalArgumentException.class,
                () -> EndpointPolicy.normalize("http://appht.jjmxg.xyz", false));
    }

    @Test
    public void rejectsUnsafeOrAmbiguousUrls() {
        assertThrows(IllegalArgumentException.class, () -> EndpointPolicy.normalize("file:///tmp/api"));
        assertThrows(IllegalArgumentException.class, () -> EndpointPolicy.normalize("https://user@example.com"));
        assertThrows(IllegalArgumentException.class, () -> EndpointPolicy.normalize("https://example.com?a=1"));
        assertThrows(IllegalArgumentException.class, () -> EndpointPolicy.normalize(""));
    }

    @Test
    public void normalizesAndDeduplicatesOrderedSelfHostRoutes() {
        List<String> routes = EndpointPolicy.normalizeAll(
            "HTTPS://API.EXAMPLE.COM:443/v1;https://backup.example.com/v1/,https://api.example.com/v1/",
            false
        );
        assertEquals(Arrays.asList(
            "https://api.example.com/v1/",
            "https://backup.example.com/v1/"
        ), routes);
    }

    @Test
    public void selfHostHttpRequiresExplicitOptIn() {
        assertThrows(IllegalArgumentException.class,
            () -> EndpointPolicy.normalizeAll("http://lan.example.test:8080/", false));
        assertEquals(
            Arrays.asList("http://lan.example.test:8080/", "https://wan.example.test/"),
            EndpointPolicy.normalizeAll(
                "http://lan.example.test:8080/;https://wan.example.test/",
                true
            )
        );
    }

    @Test
    public void compiledPrimaryMismatchAndDisabledFailoverFailClosed() {
        assertThrows(IllegalArgumentException.class, () -> EndpointPolicy.configuredRoutes(
            "https://one.example/", "https://two.example/", false, true));
        assertThrows(IllegalArgumentException.class, () -> EndpointPolicy.configuredRoutes(
            "https://one.example/", "https://one.example/;https://two.example/", false, false));
        assertEquals(
            Arrays.asList("https://appht.jjmxg.xyz/"),
            EndpointPolicy.configuredRoutes(
                "https://appht.jjmxg.xyz/",
                "https://appht.jjmxg.xyz/",
                false,
                false
            )
        );
    }
}
