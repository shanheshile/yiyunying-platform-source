package xyz.jjmxg.yiyunying.ui.moment;

/** Keeps public and profile timeline presentation rules consistent. */
final class MomentDisplayPolicy {
    private MomentDisplayPolicy() {}

    static boolean showsProfileSections(long targetMomentId, long targetUserId) {
        return targetMomentId <= 0L && targetUserId > 0L;
    }

    static boolean isManageable(boolean canPin, boolean canEdit, boolean canHide, boolean canDelete) {
        return canPin || canEdit || canHide || canDelete;
    }
}
