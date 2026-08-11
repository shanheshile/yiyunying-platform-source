package xyz.jjmxg.yiyunying.domain.forum;

/** Small, deterministic paging rules for an expanded forum reply thread. */
public final class ForumThreadPaginationPolicy {
    private ForumThreadPaginationPolicy() { }

    public static int page(int value, int totalPages) {
        int pages = Math.max(1, totalPages);
        return Math.max(1, Math.min(value, pages));
    }

    public static int totalPages(int value) {
        return Math.max(1, value);
    }

    public static boolean canPrevious(int page) {
        return page > 1;
    }

    public static boolean canNext(int page, int totalPages) {
        return page < Math.max(1, totalPages);
    }

    public static String label(int page, int totalPages) {
        int pages = totalPages(totalPages);
        return "第 " + page(page, pages) + "/" + pages + " 页";
    }
}
