package xyz.jjmxg.yiyunying.domain.forum;

import java.util.Locale;

/** Stable API values and fully localized labels for forum/post/comment sorting. */
public final class ForumSortPolicy {
    public static final String COMPREHENSIVE = "comprehensive";
    public static final String HOT = "hot";
    public static final String LATEST = "latest";
    public static final String EARLIEST = "earliest";

    private static final String[] VALUES = {
        COMPREHENSIVE, HOT, LATEST, EARLIEST
    };
    private static final String[] LABELS = {
        "综合排序", "热度优先", "最新优先", "最早优先"
    };

    private ForumSortPolicy() { }

    public static String normalize(String value) {
        String normalized = value == null ? "" : value.trim().toLowerCase(Locale.ROOT);
        for (String candidate : VALUES) {
            if (candidate.equals(normalized)) return candidate;
        }
        return COMPREHENSIVE;
    }

    public static String label(String value) {
        String normalized = normalize(value);
        for (int index = 0; index < VALUES.length; index++) {
            if (VALUES[index].equals(normalized)) return LABELS[index];
        }
        return LABELS[0];
    }

    public static int selectedIndex(String value) {
        String normalized = normalize(value);
        for (int index = 0; index < VALUES.length; index++) {
            if (VALUES[index].equals(normalized)) return index;
        }
        return 0;
    }

    public static String valueAt(int index) {
        return index >= 0 && index < VALUES.length ? VALUES[index] : COMPREHENSIVE;
    }

    public static String[] labels() {
        return LABELS.clone();
    }
}
