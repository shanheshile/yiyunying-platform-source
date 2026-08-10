package xyz.jjmxg.yiyunying.ui.common;

import java.util.regex.Matcher;
import java.util.regex.Pattern;

/** Pure decision logic for safely continuing an APK download. */
final class UpdateResumePolicy {
    private static final Pattern CONTENT_RANGE = Pattern.compile(
        "^bytes\\s+(\\d+)-(\\d+)/(\\d+|\\*)$", Pattern.CASE_INSENSITIVE);
    private static final Pattern UNSATISFIED_RANGE = Pattern.compile(
        "^bytes\\s+\\*/(\\d+)$", Pattern.CASE_INSENSITIVE);

    enum Action {
        APPEND,
        RESTART,
        VERIFY_LOCAL,
        FAIL
    }

    static final class Decision {
        final Action action;
        final long start;
        final long total;
        final String reason;

        Decision(Action action, long start, long total, String reason) {
            this.action = action;
            this.start = start;
            this.total = total;
            this.reason = reason == null ? "" : reason;
        }
    }

    private UpdateResumePolicy() { }

    static long resumableOffset(long localBytes, long expectedSize) {
        if (localBytes <= 0L || expectedSize <= 0L || localBytes > expectedSize) return 0L;
        return localBytes;
    }

    static Decision decide(
        long requestedOffset,
        long expectedSize,
        int responseCode,
        String contentRange,
        long contentLength
    ) {
        if (expectedSize <= 0L || requestedOffset < 0L || requestedOffset > expectedSize) {
            return fail("更新元数据或本地分片大小无效");
        }
        if (responseCode == 416) {
            Matcher unsatisfied = UNSATISFIED_RANGE.matcher(
                contentRange == null ? "" : contentRange.trim());
            long serverTotal = -1L;
            if (unsatisfied.matches()) {
                try { serverTotal = Long.parseLong(unsatisfied.group(1)); }
                catch (NumberFormatException ignored) { serverTotal = -1L; }
            }
            return requestedOffset == expectedSize && serverTotal == expectedSize
                ? new Decision(Action.VERIFY_LOCAL, requestedOffset, expectedSize, "")
                : new Decision(Action.RESTART, 0L, expectedSize, "服务器拒绝当前续传位置");
        }
        if (responseCode == 200) {
            return new Decision(Action.RESTART, 0L, expectedSize,
                requestedOffset > 0L ? "服务器未接受断点，已安全地从头下载" : "");
        }
        if (responseCode != 206) {
            return fail("下载服务器返回 HTTP " + responseCode);
        }

        Matcher matcher = CONTENT_RANGE.matcher(contentRange == null ? "" : contentRange.trim());
        if (!matcher.matches()) return fail("续传响应缺少有效的 Content-Range");
        long start;
        long end;
        long total;
        try {
            start = Long.parseLong(matcher.group(1));
            end = Long.parseLong(matcher.group(2));
            total = "*".equals(matcher.group(3)) ? -1L : Long.parseLong(matcher.group(3));
        } catch (NumberFormatException exception) {
            return fail("续传范围数值无效");
        }
        if (start != requestedOffset || end < start) {
            return fail("续传响应起点与本地进度不一致");
        }
        if (total != expectedSize || end >= total) {
            return fail("续传响应总大小与更新信息不一致");
        }
        long rangeLength = end - start + 1L;
        if (contentLength >= 0L && contentLength != rangeLength) {
            return fail("续传响应长度与 Content-Range 不一致");
        }
        return new Decision(Action.APPEND, start, total, "");
    }

    private static Decision fail(String reason) {
        return new Decision(Action.FAIL, 0L, -1L, reason);
    }
}
