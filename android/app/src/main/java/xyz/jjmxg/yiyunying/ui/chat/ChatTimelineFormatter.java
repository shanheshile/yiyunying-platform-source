package xyz.jjmxg.yiyunying.ui.chat;

import java.text.ParseException;
import java.text.SimpleDateFormat;
import java.util.Calendar;
import java.util.Date;
import java.util.Locale;
import java.util.TimeZone;

/** Pure time-node policy used by chat rows and unit tests. */
final class ChatTimelineFormatter {
    private static final long NODE_INTERVAL_MS = 5L * 60L * 1000L;
    private static final String[] PATTERNS = {
        "yyyy-MM-dd HH:mm:ss",
        "yyyy-MM-dd HH:mm",
        "yyyy-MM-dd'T'HH:mm:ss.SSSXXX",
        "yyyy-MM-dd'T'HH:mm:ssXXX",
        "yyyy-MM-dd'T'HH:mm:ss'Z'"
    };

    private ChatTimelineFormatter() { }

    static boolean shouldShow(String previous, String current) {
        long currentMillis = parseMillis(current);
        if (currentMillis <= 0L) return false;
        long previousMillis = parseMillis(previous);
        if (previousMillis <= 0L) return true;
        if (!sameDay(previousMillis, currentMillis)) return true;
        long delta = currentMillis - previousMillis;
        return delta < 0L || delta >= NODE_INTERVAL_MS;
    }

    static String label(String timestamp, long nowMillis, boolean detailed) {
        long value = parseMillis(timestamp);
        if (value <= 0L) return "";
        if (detailed) return format(value, "yyyy年 M月d日 E HH:mm");
        if (sameDay(value, nowMillis)) return format(value, "HH:mm");
        if (sameWeek(value, nowMillis)) return format(value, "M月d日 E HH:mm");
        if (sameYear(value, nowMillis)) return format(value, "M月d日 HH:mm");
        return format(value, "yyyy年 M月d日 HH:mm");
    }

    static long parseMillis(String raw) {
        if (raw == null) return 0L;
        String value = raw.trim();
        if (value.isEmpty()) return 0L;
        try {
            long numeric = Long.parseLong(value);
            return numeric > 0L && numeric < 10_000_000_000L ? numeric * 1000L : numeric;
        } catch (NumberFormatException ignored) {
            // Continue with server date formats.
        }
        for (String pattern : PATTERNS) {
            SimpleDateFormat parser = new SimpleDateFormat(pattern, Locale.CHINA);
            parser.setLenient(false);
            if (pattern.endsWith("'Z'")) parser.setTimeZone(TimeZone.getTimeZone("UTC"));
            try {
                Date parsed = parser.parse(value);
                if (parsed != null) return parsed.getTime();
            } catch (ParseException ignored) {
                // Try the next supported format.
            }
        }
        return 0L;
    }

    private static boolean sameDay(long left, long right) {
        Calendar a = calendar(left);
        Calendar b = calendar(right);
        return a.get(Calendar.ERA) == b.get(Calendar.ERA)
            && a.get(Calendar.YEAR) == b.get(Calendar.YEAR)
            && a.get(Calendar.DAY_OF_YEAR) == b.get(Calendar.DAY_OF_YEAR);
    }

    private static boolean sameWeek(long left, long right) {
        Calendar a = calendar(left);
        Calendar b = calendar(right);
        return a.getWeekYear() == b.getWeekYear()
            && a.get(Calendar.WEEK_OF_YEAR) == b.get(Calendar.WEEK_OF_YEAR);
    }

    private static boolean sameYear(long left, long right) {
        return calendar(left).get(Calendar.YEAR) == calendar(right).get(Calendar.YEAR);
    }

    private static Calendar calendar(long millis) {
        Calendar value = Calendar.getInstance(Locale.CHINA);
        value.setTimeInMillis(millis);
        return value;
    }

    private static String format(long millis, String pattern) {
        return new SimpleDateFormat(pattern, Locale.CHINA).format(new Date(millis));
    }
}
