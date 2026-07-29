package xyz.jjmxg.yiyunying.domain.module;

import com.google.gson.JsonElement;
import com.google.gson.JsonObject;

import java.util.regex.Matcher;
import java.util.regex.Pattern;

import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.data.session.SessionManager;

public final class PathResolver {
    private static final Pattern PLACEHOLDER = Pattern.compile("\\{([a-zA-Z0-9_]+)\\}");

    private PathResolver() {
    }

    public static String resolve(String template, SessionManager session, JsonObject item) {
        return resolve(template, session.selectedAppId(), item);
    }

    public static String resolve(String template, long appId, JsonObject item) {
        boolean targetAppItem = item != null && hasOnlyAppPlaceholder(template);
        Matcher matcher = PLACEHOLDER.matcher(template);
        StringBuffer result = new StringBuffer();
        while (matcher.find()) {
            String key = matcher.group(1);
            String value;
            if ("app_id".equals(key)) {
                value = targetAppItem ? value(item, key) : String.valueOf(appId);
            } else {
                value = value(item, key);
            }
            if (value.isEmpty() || "0".equals(value) && "app_id".equals(key)) {
                throw new IllegalArgumentException("缺少路径参数：" + key);
            }
            matcher.appendReplacement(result, Matcher.quoteReplacement(value));
        }
        matcher.appendTail(result);
        return result.toString();
    }

    private static boolean hasOnlyAppPlaceholder(String template) {
        Matcher matcher = PLACEHOLDER.matcher(template);
        int count = 0;
        while (matcher.find()) {
            count++;
            if (!"app_id".equals(matcher.group(1))) return false;
        }
        return count == 1;
    }

    private static String value(JsonObject item, String key) {
        if (item == null) {
            return "";
        }
        JsonElement direct = item.get(key);
        if (direct != null && direct.isJsonPrimitive()) {
            return direct.getAsString();
        }
        if (key.endsWith("_id")) {
            String fallback = key.substring(0, key.length() - 3) + "id";
            String found = Jsons.string(item, fallback);
            if (!found.isEmpty()) {
                return found;
            }
        }
        return Jsons.string(item, "id");
    }
}
