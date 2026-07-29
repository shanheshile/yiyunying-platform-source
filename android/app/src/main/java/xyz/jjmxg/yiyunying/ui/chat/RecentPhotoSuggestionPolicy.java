package xyz.jjmxg.yiyunying.ui.chat;

final class RecentPhotoSuggestionPolicy {
    enum Decision {
        INITIALIZE,
        IGNORE,
        SHOW
    }

    private RecentPhotoSuggestionPolicy() { }

    static Decision decide(
        boolean baselineReady,
        long previousAddedAt,
        long previousId,
        long currentAddedAt,
        long currentId
    ) {
        if (!baselineReady) return Decision.INITIALIZE;
        if (currentId <= 0) return Decision.IGNORE;
        if (currentAddedAt > previousAddedAt) return Decision.SHOW;
        if (currentAddedAt == previousAddedAt && currentId > previousId) return Decision.SHOW;
        return Decision.IGNORE;
    }
}
