package xyz.jjmxg.yiyunying.ui.moment;

/** Keeps public and profile timeline presentation rules consistent. */
final class MomentDisplayPolicy {
    private MomentDisplayPolicy() {}

    static boolean showsProfileSections(long targetMomentId, boolean profileTimeline) {
        return targetMomentId <= 0L && profileTimeline;
    }

    static boolean showsPinnedDivider(boolean profileSections, boolean currentPinned,
                                      boolean hasNextItem, boolean nextPinned) {
        return profileSections && currentPinned && hasNextItem && !nextPinned;
    }

    static boolean requiresFriendSelection(String visibilityMode) {
        return "selected".equals(visibilityMode) || "exclude".equals(visibilityMode);
    }

    static boolean usesMineQuery(boolean profileTimeline, boolean explicitMine, long targetUserId, long actorId) {
        if (!profileTimeline || actorId <= 0L) return false;
        return explicitMine || targetUserId <= 0L || targetUserId == actorId;
    }

    static boolean isManageable(
        boolean canPin,
        boolean canEdit,
        boolean canEditVisibility,
        boolean canHide,
        boolean canDelete
    ) {
        return canPin || canEdit || canEditVisibility || canHide || canDelete;
    }
}
