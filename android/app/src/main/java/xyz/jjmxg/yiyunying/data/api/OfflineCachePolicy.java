package xyz.jjmxg.yiyunying.data.api;

import java.util.Arrays;
import java.util.HashSet;
import java.util.Locale;
import java.util.Set;

import okhttp3.HttpUrl;

/** Defines which read models are durable and how paged requests share an offline fallback. */
final class OfflineCachePolicy {
    private static final Set<String> TRANSIENT_QUERY = new HashSet<>(Arrays.asList(
        "page", "per_page", "page_size", "limit", "offset", "cursor", "before_id",
        "after_id", "since_id", "from_id", "last_id"
    ));

    private OfflineCachePolicy() { }

    static boolean isCacheable(ApiRequest request) {
        if (request == null || !"GET".equals(request.method())) return false;
        String path = request.path().toLowerCase(Locale.ROOT);
        return !path.contains("heartbeat")
            && !path.contains("logout")
            && !path.contains("/token/")
            && !path.contains("captcha");
    }

    static String resourceKey(String namespace, String requestUrl) {
        if (requestUrl == null || requestUrl.trim().isEmpty()) return safe(namespace);
        try {
            HttpUrl source = HttpUrl.get(requestUrl);
            HttpUrl.Builder stable = source.newBuilder().query(null);
            for (String name : source.queryParameterNames()) {
                if (TRANSIENT_QUERY.contains(name.toLowerCase(Locale.ROOT))) continue;
                for (String value : source.queryParameterValues(name)) {
                    stable.addQueryParameter(name, value);
                }
            }
            return safe(namespace) + "|" + stable.build();
        } catch (RuntimeException ignored) {
            return safe(namespace) + "|" + requestUrl;
        }
    }

    static String contentKind(String requestUrl) {
        String value = requestUrl == null ? "" : requestUrl.toLowerCase(Locale.ROOT);
        if (containsAny(value, "/friends", "/contacts", "/followers", "/following", "/likes")) {
            return "联系人与互动";
        }
        if (containsAny(value, "/profile", "/users/", "/user/info", "/user/me")) return "个人资料";
        if (containsAny(value, "/customer-service", "/support-chat", "/service-chat")) return "客服会话";
        if (containsAny(value, "/messages", "/conversations", "/chat-rooms", "/groups")) return "聊天与群组";
        if (containsAny(value, "/forward", "/favorites", "/collections")) return "转发与收藏";
        return "只读内容";
    }

    private static boolean containsAny(String value, String... needles) {
        for (String needle : needles) if (value.contains(needle)) return true;
        return false;
    }

    private static String safe(String value) {
        return value == null ? "" : value;
    }
}
