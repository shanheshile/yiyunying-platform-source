package xyz.jjmxg.yiyunying.domain.document;

import java.net.URI;
import java.net.URLDecoder;
import java.nio.charset.StandardCharsets;
import java.util.Locale;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

public final class ShareCodeParser {
    private static final String CODE = "[A-Za-z0-9_-]{8,48}";
    private static final Pattern LABELED = Pattern.compile(
        "(?i)(?:分享码|share[\\s_-]*code)\\s*[:：=]?\\s*(" + CODE + ")"
    );
    private static final Pattern URL_PATH = Pattern.compile(
        "(?i)/(?:api/public/)?(?:note|document)-shares/(" + CODE + ")"
    );
    private static final Pattern DIRECT = Pattern.compile("^" + CODE + "$");

    private ShareCodeParser() {
    }

    public static String parse(String raw, boolean allowPlainCode) {
        if (raw == null) return "";
        String value = raw.trim();
        if (value.isEmpty() || value.length() > 4096) return "";

        Matcher labeled = LABELED.matcher(value);
        if (labeled.find()) return labeled.group(1);

        String decoded = decode(value);
        Matcher path = URL_PATH.matcher(decoded);
        if (path.find()) return path.group(1);

        String queryCode = queryParameter(decoded, "share_code");
        if (DIRECT.matcher(queryCode).matches()) return queryCode;

        if (allowPlainCode && DIRECT.matcher(value).matches()) return value;
        return "";
    }

    public static boolean isExplicitShareText(String raw) {
        if (raw == null) return false;
        String lower = raw.toLowerCase(Locale.ROOT);
        return lower.contains("分享码") || lower.contains("share_code")
            || lower.contains("share-code") || lower.contains("note-shares/")
            || lower.contains("document-shares/");
    }

    private static String queryParameter(String value, String name) {
        try {
            URI uri = URI.create(value);
            String query = uri.getRawQuery();
            if (query == null) return "";
            for (String pair : query.split("&")) {
                int split = pair.indexOf('=');
                String key = decode(split < 0 ? pair : pair.substring(0, split));
                if (name.equalsIgnoreCase(key)) {
                    return decode(split < 0 ? "" : pair.substring(split + 1));
                }
            }
        } catch (RuntimeException ignored) {
            return "";
        }
        return "";
    }

    private static String decode(String value) {
        try {
            return URLDecoder.decode(value, StandardCharsets.UTF_8.name());
        } catch (Exception ignored) {
            return value;
        }
    }
}
