package xyz.jjmxg.yiyunying.data.api;

import java.util.Locale;

/**
 * Keeps operation feedback tied to the action the user just performed.
 * Specific business messages are preserved; generic transport messages use
 * the caller's contextual fallback instead.
 */
public final class ActionFeedback {
    private ActionFeedback() {
    }

    public static String message(ApiResult result, String successFallback, String failureFallback) {
        if (result == null) return normalize(failureFallback, "操作失败，请稍后重试");
        return result.isSuccessful()
            ? success(result, successFallback)
            : failure(result, failureFallback);
    }

    public static String success(ApiResult result, String fallback) {
        String contextual = normalize(fallback, "操作成功");
        String value = result == null ? "" : normalize(result.message(), "");
        return isGenericSuccess(value) ? contextual : value;
    }

    public static String failure(ApiResult result, String fallback) {
        String contextual = normalize(fallback, "操作失败，请稍后重试");
        String value = result == null ? "" : normalize(result.message(), "");
        return isGenericFailure(value) ? contextual : value;
    }

    private static boolean isGenericSuccess(String value) {
        if (value.isEmpty()) return true;
        String lower = value.toLowerCase(Locale.ROOT);
        return "操作成功".equals(value)
            || "请求成功".equals(value)
            || "success".equals(lower)
            || "ok".equals(lower);
    }

    private static boolean isGenericFailure(String value) {
        if (value.isEmpty()) return true;
        return "请求处理失败，请稍后重试。".equals(value)
            || "服务器暂时无法处理请求，请稍后重试。".equals(value);
    }

    private static String normalize(String value, String fallback) {
        if (value == null) return fallback;
        String cleaned = value.trim();
        return cleaned.isEmpty() ? fallback : cleaned;
    }
}
