package xyz.jjmxg.yiyunying.ui.moment;

/** Keeps public and profile timeline presentation rules consistent. */
final class MomentDisplayPolicy {
    private MomentDisplayPolicy() {}

    static boolean showsProfileSections(long targetMomentId, boolean profileTimeline) {
        return targetMomentId <= 0L && profileTimeline;
    }

    static boolean isManageable(boolean canPin, boolean canEdit, boolean canHide, boolean canDelete) {
        return canPin || canEdit || canHide || canDelete;
    }
}
