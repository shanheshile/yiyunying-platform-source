package xyz.jjmxg.yiyunying.data.api;

import com.google.gson.JsonArray;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;
import com.google.gson.JsonParser;

import java.util.Locale;
import java.util.regex.Pattern;

/** Converts server-facing diagnostics into short text that is safe to show in every app edition. */
public final class ApiVisibleMessage {
    private static final String[] MESSAGE_KEYS = {
        "msg", "message", "error", "detail", "reason", "description", "title"
    };
    private static final Pattern HTML_TAG = Pattern.compile("<[^>]+>");
    private static final Pattern WHITESPACE = Pattern.compile("\\s+");
    private static final Pattern TECHNICAL_TRACE = Pattern.compile(
        "(?is).*(?:php\\s+(?:warning|notice|fatal)|notice:\\s*php|proc_open\\s*\\(|permission denied|"
            + "stack trace|uncaught (?:exception|error)|\\bat\\s+[a-z0-9_.$]+\\([^)]*\\.(?:java|php):\\d+|"
            + "(?:java\\.|android\\.)[a-z0-9_.$]*(?:exception|error)|caused by:|"
            + "/www/wwwroot|[a-z]:\\\\[^\\r\\n]+|sqlstate\\[).*"
    );
    private static final Pattern JSON_SHAPE = Pattern.compile(
        "(?s)^\\s*[\\[{].*[\\]}]\\s*$"
    );

    private ApiVisibleMessage() {
    }

    public static String visible(String raw, int httpCode, int businessCode) {
        String value = normalize(raw);
        if (value.isEmpty()) {
            return fallback(httpCode, businessCode);
        }

        String extracted = extractJsonMessage(value, 0);
        if (!extracted.isEmpty()) {
            value = normalize(extracted);
        } else if (looksSerializedPayload(value)) {
            return fallback(httpCode, businessCode);
        }

        String lower = value.toLowerCase(Locale.ROOT);
        if (looksLikeHtml(lower) || TECHNICAL_TRACE.matcher(value).matches()) {
            return fallback(httpCode >= 400 ? httpCode : 500, businessCode);
        }
        if (lower.contains("failed to connect") || lower.contains("connection refused")
            || lower.contains("unable to resolve host") || lower.contains("timeout")
            || lower.contains("timed out")) {
            return "网络连接失败，请检查网络后重试。";
        }

        value = HTML_TAG.matcher(value).replaceAll(" ");
        value = normalize(value);
        if (value.isEmpty() || JSON_SHAPE.matcher(value).matches()) {
            return fallback(httpCode, businessCode);
        }
        if (value.length() > 240) {
            value = value.substring(0, 240).trim() + "…";
        }
        return value;
    }

    /**
     * Sanitizes server-owned business copy without shortening legitimate long-form content.
     * Serialized payloads, HTML error pages and runtime diagnostics are replaced with fallback.
     */
    public static String visibleContent(String raw, String fallback) {
        String safeFallback = normalize(fallback);
        if (safeFallback.isEmpty()) safeFallback = "内容暂不可显示";

        String value = normalize(raw);
        if (value.isEmpty()) return safeFallback;

        String extracted = extractJsonMessage(value, 0);
        if (!extracted.isEmpty()) {
            value = normalize(extracted);
        } else if (looksSerializedPayload(value)) {
            return safeFallback;
        }

        String lower = value.toLowerCase(Locale.ROOT);
        if (looksLikeHtml(lower) || TECHNICAL_TRACE.matcher(value).matches()) {
            return safeFallback;
        }
        if (lower.contains("failed to connect") || lower.contains("connection refused")
            || lower.contains("unable to resolve host") || lower.contains("timeout")
            || lower.contains("timed out")) {
            return "网络连接失败，请检查网络后重试。";
        }

        value = normalize(HTML_TAG.matcher(value).replaceAll(" "));
        return value.isEmpty() || JSON_SHAPE.matcher(value).matches() ? safeFallback : value;
    }

    private static String extractJsonMessage(String value, int depth) {
        if (depth > 4 || value.isEmpty()) {
            return "";
        }
        char first = value.charAt(0);
        if (first != '{' && first != '[' && first != '"') {
            return "";
        }
        try {
            JsonElement parsed = JsonParser.parseString(value);
            // A quoted scalar or primitive array is still serialized data, not a user-facing message.
            // Only explicit message fields inside an object may unwrap primitive text.
            if (depth == 0 && parsed.isJsonPrimitive()) return "";
            return extractElement(parsed, depth + 1);
        } catch (RuntimeException ignored) {
            return "";
        }
    }

    private static String extractElement(JsonElement element, int depth) {
        if (element == null || element.isJsonNull() || depth > 5) {
            return "";
        }
        if (element.isJsonPrimitive()) {
            String primitive;
            try {
                primitive = normalize(element.getAsString());
            } catch (RuntimeException ignored) {
                return "";
            }
            String nested = extractJsonMessage(primitive, depth + 1);
            return nested.isEmpty() ? primitive : nested;
        }
        if (element.isJsonObject()) {
            JsonObject object = element.getAsJsonObject();
            for (String key : MESSAGE_KEYS) {
                if (!object.has(key)) continue;
                String result = extractElement(object.get(key), depth + 1);
                if (!result.isEmpty()) return result;
            }
            if (object.has("data")) {
                String result = extractElement(object.get("data"), depth + 1);
                if (!result.isEmpty()) return result;
            }
            return "";
        }
        JsonArray array = element.getAsJsonArray();
        for (JsonElement item : array) {
            if (item == null || item.isJsonNull() || item.isJsonPrimitive()) continue;
            String result = extractElement(item, depth + 1);
            if (!result.isEmpty()) return result;
        }
        return "";
    }

    private static String fallback(int httpCode, int businessCode) {
        if (httpCode >= 200 && httpCode < 300 && businessCode == 1) {
            return "操作成功";
        }
        if (httpCode == 0) {
            return "网络连接失败，请检查网络后重试。";
        }
        if (httpCode == 401 || businessCode == 401) {
            return "登录状态已失效，请重新登录。";
        }
        if (httpCode == 403 || businessCode == 403) {
            return "当前账号没有此操作权限。";
        }
        if (httpCode == 404 || businessCode == 404) {
            return "请求的内容不存在或已被删除。";
        }
        if (httpCode == 409 || businessCode == 409) {
            return "当前内容已发生变化，请刷新后重试。";
        }
        if (httpCode == 413 || businessCode == 413) {
            return "上传内容过大，请压缩后重试或联系管理员调整上传限制。";
        }
        if (httpCode == 422 || businessCode == 422) {
            return "提交内容不完整或格式不正确，请检查后重试。";
        }
        if (httpCode == 429 || businessCode == 429) {
            return "操作过于频繁，请稍后重试。";
        }
        if (httpCode >= 500 || businessCode >= 500) {
            return "服务器暂时无法处理请求，请稍后重试。";
        }
        return "请求处理失败，请稍后重试。";
    }

    private static boolean looksLikeHtml(String lower) {
        return lower.startsWith("<!doctype html") || lower.startsWith("<html")
            || lower.contains("<head>") || lower.contains("<body>");
    }

    private static boolean looksSerializedPayload(String value) {
        if (value == null || value.isEmpty()) return false;
        char first = value.charAt(0);
        if (first != '{' && first != '[' && first != '"') {
            return JSON_SHAPE.matcher(value).matches();
        }
        try {
            JsonParser.parseString(value);
            return true;
        } catch (RuntimeException ignored) {
            return JSON_SHAPE.matcher(value).matches();
        }
    }

    private static String normalize(String value) {
        if (value == null) return "";
        String cleaned = value.replace('\ufeff', ' ').replace('\u0000', ' ').trim();
        return WHITESPACE.matcher(cleaned).replaceAll(" ").trim();
    }
}
