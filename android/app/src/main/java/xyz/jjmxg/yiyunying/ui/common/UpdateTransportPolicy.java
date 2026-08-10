package xyz.jjmxg.yiyunying.ui.common;

import java.net.URI;
import java.net.URISyntaxException;
import java.util.Locale;

/** Network transport rules applied after redirects as well as before a download. */
final class UpdateTransportPolicy {
    private UpdateTransportPolicy() { }

    static boolean allows(String originalUrl, String finalUrl) {
        return allows(originalUrl, finalUrl, true);
    }

    static boolean allows(String originalUrl, String finalUrl, boolean allowHttp) {
        String original = scheme(originalUrl);
        String resolved = scheme(finalUrl);
        if (!("http".equals(original) || "https".equals(original))) return false;
        if (!("http".equals(resolved) || "https".equals(resolved))) return false;
        if (!allowHttp && (!"https".equals(original) || !"https".equals(resolved))) return false;
        return !("https".equals(original) && "http".equals(resolved));
    }

    static String ifRange(String etag, String lastModified) {
        String normalizedEtag = value(etag);
        if (!normalizedEtag.isEmpty() && !normalizedEtag.regionMatches(true, 0, "W/", 0, 2)) {
            return normalizedEtag;
        }
        return value(lastModified);
    }

    private static String scheme(String value) {
        try {
            URI uri = new URI(value(value));
            return value(uri.getScheme()).toLowerCase(Locale.ROOT);
        } catch (URISyntaxException exception) {
            return "";
        }
    }

    private static String value(String value) {
        return value == null ? "" : value.trim();
    }
}
