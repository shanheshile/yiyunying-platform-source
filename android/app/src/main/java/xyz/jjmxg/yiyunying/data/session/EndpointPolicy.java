package xyz.jjmxg.yiyunying.data.session;

import java.util.ArrayList;
import java.util.Collections;
import java.util.LinkedHashSet;
import java.util.List;

import okhttp3.HttpUrl;

import xyz.jjmxg.yiyunying.BuildConfig;

public final class EndpointPolicy {
    private EndpointPolicy() {
    }

    public static String normalize(String raw) {
        return normalize(raw, BuildConfig.ALLOW_HTTP_ENDPOINTS);
    }

    public static String normalize(String raw, boolean allowHttpEndpoints) {
        String value = raw == null ? "" : raw.trim();
        if (value.isEmpty()) {
            throw new IllegalArgumentException("服务器地址不能为空");
        }
        if (value.matches("(?i)^[a-z][a-z0-9+.-]*://.*")
            && !value.matches("(?i)^https?://.*")) {
            throw new IllegalArgumentException("服务器地址只支持 HTTP 或 HTTPS");
        }
        if (!value.matches("(?i)^https?://.*")) {
            value = (allowHttpEndpoints ? "http://" : "https://") + value;
        }
        final HttpUrl url;
        try {
            url = HttpUrl.get(value);
        } catch (IllegalArgumentException exception) {
            throw new IllegalArgumentException("服务器地址格式错误");
        }
        if ("http".equals(url.scheme()) && !allowHttpEndpoints) {
            throw new IllegalArgumentException("正式版本只允许使用 HTTPS 服务器地址");
        }
        if (!url.username().isEmpty() || !url.password().isEmpty()
                || url.query() != null || url.fragment() != null) {
            throw new IllegalArgumentException("服务器地址不能包含账号、查询参数或片段");
        }
        String normalized = url.toString();
        return normalized.endsWith("/") ? normalized : normalized + "/";
    }

    /**
     * Parses the BuildConfig endpoint list while preserving order and removing
     * canonical duplicates. Comma, semicolon and line breaks are accepted only
     * as build-time separators; endpoint URLs themselves may not carry queries.
     */
    public static List<String> normalizeAll(String encoded, boolean allowHttpEndpoints) {
        LinkedHashSet<String> normalized = new LinkedHashSet<>();
        String value = encoded == null ? "" : encoded;
        for (String candidate : value.split("[;,\\r\\n]+")) {
            if (!candidate.trim().isEmpty()) {
                normalized.add(normalize(candidate, allowHttpEndpoints));
            }
        }
        if (normalized.isEmpty()) {
            throw new IllegalArgumentException("至少需要配置一条服务器线路");
        }
        return Collections.unmodifiableList(new ArrayList<>(normalized));
    }

    /**
     * Binds a persisted primary endpoint to its compiled route set. A mismatch
     * fails closed instead of silently sending credentials to a stale endpoint.
     */
    public static List<String> configuredRoutes(
        String primary,
        String encoded,
        boolean allowHttpEndpoints,
        boolean allowFailover
    ) {
        String normalizedPrimary = normalize(primary, allowHttpEndpoints);
        List<String> configured = normalizeAll(encoded, allowHttpEndpoints);
        if (!normalizedPrimary.equals(configured.get(0))) {
            throw new IllegalArgumentException("主服务器与编译线路身份不一致");
        }
        if (!allowFailover) {
            if (configured.size() != 1) {
                throw new IllegalArgumentException("当前构建禁止多线路切换");
            }
            return Collections.singletonList(normalizedPrimary);
        }
        return configured;
    }
}
