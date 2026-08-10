package xyz.jjmxg.yiyunying.ui.common;

import java.util.Collections;
import java.util.HashSet;
import java.util.Set;

/** Directional signing-certificate policy: an old APK cannot ride installed history backwards. */
final class UpdateSigningPolicy {
    private UpdateSigningPolicy() { }

    static boolean allows(
        Set<String> installedCurrent,
        Set<String> candidateCurrent,
        Set<String> candidateHistory
    ) {
        Set<String> installed = normalized(installedCurrent);
        Set<String> candidate = normalized(candidateCurrent);
        Set<String> history = normalized(candidateHistory);
        if (installed.isEmpty() || candidate.isEmpty()) return false;
        if (installed.equals(candidate)) return true;
        // APK signer rotation is one-to-one. The candidate must carry the installed current
        // certificate forward in its own proof-of-rotation history. Installed history is never
        // used here, otherwise a stale APK signed by an old key could be accepted backwards.
        return installed.size() == 1
            && candidate.size() == 1
            && history.size() > 1
            && history.contains(installed.iterator().next())
            && history.contains(candidate.iterator().next());
    }

    private static Set<String> normalized(Set<String> source) {
        if (source == null || source.isEmpty()) return Collections.emptySet();
        Set<String> result = new HashSet<>();
        for (String value : source) {
            String normalized = value == null ? "" : value.trim().toLowerCase(java.util.Locale.ROOT);
            if (normalized.matches("[0-9a-f]{64}")) result.add(normalized);
        }
        return result;
    }
}
