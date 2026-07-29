package xyz.jjmxg.yiyunying.ui.bot;

import java.util.Locale;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

public final class BotQuestionClassifier {
    private static final String[] WEATHER_KEYWORDS = {
        "天气", "气温", "温度", "体感", "预报", "下雨", "降雨", "带伞", "雨伞",
        "下雪", "风力", "风速", "湿度", "气压", "紫外线", "日出", "日落", "穿什么",
        "防晒", "能洗车", "适合运动", "weather", "temperature", "forecast", "rain",
        "snow", "humidity", "pressure", "wind", "uv index"
    };
    private static final Pattern WEATHER_IN_PLACE = Pattern.compile(
        "(?:weather|forecast|temperature)\\s+(?:in|for)\\s+([a-z][a-z .'-]{1,60})",
        Pattern.CASE_INSENSITIVE
    );
    private static final Pattern PLACE_WEATHER = Pattern.compile(
        "([a-z][a-z .'-]{1,60}?)\\s+(?:weather|forecast|temperature)(?:\\s|$)",
        Pattern.CASE_INSENSITIVE
    );
    private static final String[] LOCATION_BLOCKLIST = {
        "今天", "今日", "明天", "明日", "次日", "后天", "现在", "目前", "最近", "近期", "未来", "这周", "本周",
        "今晚", "今夜", "明晚", "早上", "上午", "中午", "下午", "晚上", "夜间",
        "当地", "本地", "这里", "这儿", "附近", "当前位置", "我这里", "我这边", "所在位置",
        "哪里", "哪儿", "哪个地方", "什么地方", "today", "tomorrow", "here", "nearby",
        "current location", "my location"
    };
    private static final String[] LEADING_FILLERS = {
        "请问", "麻烦", "帮我查一下", "帮我查查", "帮我看看", "帮我查", "查一下", "查询",
        "看一下", "看看", "我想知道", "想知道", "告诉我", "请告诉我"
    };
    private static final String[] TRAILING_FILLERS = {
        "今天", "今日", "明天", "明日", "次日", "后天", "今晚", "今夜", "明晚", "早上", "上午", "中午", "下午", "晚上", "夜间",
        "现在", "目前", "最近", "近期", "这几天", "这两天", "这周",
        "本周", "未来", "未来几天", "未来一周", "会不会", "有没有", "是否", "可能", "需要",
        "会", "要", "的"
    };

    private BotQuestionClassifier() { }

    public static boolean isWeatherQuestion(String value) {
        if (value == null) return false;
        String question = value.trim().toLowerCase(Locale.ROOT);
        if (question.isEmpty()) return false;
        for (String keyword : WEATHER_KEYWORDS) {
            if (question.contains(keyword)) return true;
        }
        return false;
    }

    public static String extractRequestedLocation(String value) {
        if (!isWeatherQuestion(value)) return "";
        String question = value == null ? "" : value.trim();
        if (question.isEmpty()) return "";

        Matcher afterEnglishKeyword = WEATHER_IN_PLACE.matcher(question);
        if (afterEnglishKeyword.find()) return cleanEnglishLocation(afterEnglishKeyword.group(1));
        Matcher beforeEnglishKeyword = PLACE_WEATHER.matcher(question);
        if (beforeEnglishKeyword.find()) return cleanEnglishLocation(beforeEnglishKeyword.group(1));

        int keywordIndex = firstChineseWeatherKeywordIndex(question);
        if (keywordIndex < 0) return "";
        String keyword = chineseWeatherKeywordAt(question, keywordIndex);
        String candidate = cleanChineseLocation(question.substring(0, keywordIndex));
        if (looksLikeExplicitLocation(candidate)) return candidate;
        if (keyword.isEmpty()) return "";
        candidate = cleanChineseLocation(question.substring(keywordIndex + keyword.length()));
        if (!looksLikeExplicitLocation(candidate)) return "";
        return candidate;
    }

    private static String cleanChineseLocation(String value) {
        String candidate = value
            .replaceAll("[，,。.!！?？:：;；]+$", "")
            .replaceAll("^[，,。.!！?？:：;；]+", "")
            .trim();
        candidate = stripLeading(candidate);
        candidate = stripTrailing(candidate);
        candidate = candidate
            .replaceAll("(?:几点|什么时候|何时)(?:到|至|~|～|-|—|－)(?:几点|什么时候|何时)", "")
            .replaceAll("(?:今天|今日|明天|明日|次日|后天|今晚|今夜|明晚|早上|上午|中午|下午|晚上|夜间|现在|目前|最近|近期|几点|什么时候|何时|大概|预计|开始|持续|多久)", "")
            .replaceAll("^(?:到|至|从)|(?:到|至)$", "")
            .replaceFirst("^(?:在|查|看)", "")
            .trim();
        if (candidate.endsWith("的")) candidate = candidate.substring(0, candidate.length() - 1).trim();
        return candidate;
    }

    private static int firstChineseWeatherKeywordIndex(String question) {
        int result = -1;
        for (String keyword : WEATHER_KEYWORDS) {
            if (keyword.chars().allMatch(value -> value < 128)) continue;
            int index = question.indexOf(keyword);
            if (index >= 0 && (result < 0 || index < result)) result = index;
        }
        return result;
    }

    private static String chineseWeatherKeywordAt(String question, int index) {
        for (String keyword : WEATHER_KEYWORDS) {
            if (keyword.chars().allMatch(value -> value < 128)) continue;
            if (question.startsWith(keyword, index)) return keyword;
        }
        return "";
    }

    private static String stripLeading(String value) {
        String result = value.trim();
        boolean changed;
        do {
            changed = false;
            for (String filler : LEADING_FILLERS) {
                if (result.startsWith(filler)) {
                    result = result.substring(filler.length()).trim();
                    changed = true;
                }
            }
        } while (changed && !result.isEmpty());
        return result;
    }

    private static String stripTrailing(String value) {
        String result = value.trim();
        boolean changed;
        do {
            changed = false;
            for (String filler : TRAILING_FILLERS) {
                if (result.endsWith(filler)) {
                    result = result.substring(0, result.length() - filler.length()).trim();
                    changed = true;
                }
            }
            String withoutRange = result.replaceFirst("(?:未来)?[一二两三四五六七八九十\\d]+天$", "").trim();
            if (!withoutRange.equals(result)) {
                result = withoutRange;
                changed = true;
            }
        } while (changed && !result.isEmpty());
        return result;
    }

    private static boolean looksLikeExplicitLocation(String value) {
        if (value.length() < 2 || value.length() > 40) return false;
        String normalized = value.toLowerCase(Locale.ROOT);
        for (String blocked : LOCATION_BLOCKLIST) {
            if (normalized.equals(blocked) || normalized.contains(blocked)) return false;
        }
        return value.matches(".*[\\p{IsHan}A-Za-z].*")
            && !value.matches(".*(?:怎么|怎样|多少|强不强|穿什么|适合什么|能不能).*");
    }

    private static String cleanEnglishLocation(String value) {
        if (value == null) return "";
        String result = value.replaceAll("[?.!,;:]+$", "").trim();
        result = result.replaceFirst("(?i)^(?:please|tell me|show me)\\s+", "").trim();
        if (!looksLikeExplicitLocation(result)) return "";
        return result;
    }
}
