package xyz.jjmxg.yiyunying.data.session;

import java.net.URI;
import java.net.URISyntaxException;
import java.util.Locale;

public final class EndpointPolicy {
    private EndpointPolicy() {
    }

    public static String normalize(String raw) {
        String value = raw == null ? "" : raw.trim();
        if (value.isEmpty()) {
            throw new IllegalArgumentException("服务器地址不能为空");
        }
        if (value.matches("(?i)^[a-z][a-z0-9+.-]*://.*")
            && !value.matches("(?i)^https?://.*")) {
            throw new IllegalArgumentException("服务器地址只支持 HTTP 或 HTTPS");
        }
        if (!value.matches("(?i)^https?://.*")) {
            value = "http://" + value;
        }
        try {
            URI uri = new URI(value);
            String scheme = uri.getScheme() == null ? "" : uri.getScheme().toLowerCase(Locale.ROOT);
            if (!("http".equals(scheme) || "https".equals(scheme))) {
                throw new IllegalArgumentException("服务器地址只支持 HTTP 或 HTTPS");
            }
            if (uri.getHost() == null || uri.getHost().trim().isEmpty()) {
                throw new IllegalArgumentException("服务器地址缺少主机名");
            }
            if (uri.getUserInfo() != null || uri.getQuery() != null || uri.getFragment() != null) {
                throw new IllegalArgumentException("服务器地址不能包含账号、查询参数或片段");
            }
            String normalized = uri.toASCIIString();
            return normalized.endsWith("/") ? normalized : normalized + "/";
        } catch (URISyntaxException exception) {
            throw new IllegalArgumentException("服务器地址格式错误");
        }
    }
}
